<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ncmail-turbo contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\IMAP;

use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Mailbox;
use Horde_Imap_Client_Socket;
use OCA\Mail\Http\SidecarClient;
use Psr\Log\LoggerInterface;

/**
 * Fake Horde IMAP client that delegates to the Go sidecar.
 *
 * Extends Horde_Imap_Client_Socket so it can be used as a drop-in replacement
 * everywhere the codebase expects one. Overrides IMAP methods to call the Go
 * sidecar via HTTP instead of opening a TCP connection to the IMAP server.
 */
class SidecarImapClient extends Horde_Imap_Client_Socket {
	private SidecarClient $sidecar;
	private LoggerInterface $logger;
	private array $imapCreds;
	private int $accountId;
	private string $email;
	private bool $hordeConnected = false;

	/**
	 * @param array $params Horde client params (username, password, hostspec, port, secure)
	 * @param SidecarClient $sidecar HTTP transport to Go sidecar
	 * @param LoggerInterface $logger
	 * @param array $imapCreds Pre-built credential array for sidecar headers
	 * @param int $accountId Nextcloud Mail account ID
	 * @param string $email Account email address
	 */
	public function __construct(
		array $params,
		SidecarClient $sidecar,
		LoggerInterface $logger,
		array $imapCreds,
		int $accountId,
		string $email,
	) {
		$this->sidecar = $sidecar;
		$this->logger = $logger;
		$this->imapCreds = $imapCreds;
		$this->accountId = $accountId;
		$this->email = $email;

		// Parent constructor sets up internal state (capability object, etc.)
		// but does NOT open a TCP connection until login() or a command is issued.
		parent::__construct($params);
	}

	/**
	 * No-op on initial call — sidecar-overridden methods don't need a connection.
	 * Real Horde login happens lazily via _sendCmd() when an unoverridden method
	 * tries to talk to the IMAP server.
	 */
	#[\Override]
	protected function _login() {
		return true;
	}

	/**
	 * Only logout if we actually opened a Horde connection.
	 */
	#[\Override]
	public function logout() {
		if ($this->hordeConnected) {
			parent::logout();
		}
	}

	/**
	 * Intercept all Horde IMAP wire traffic. If an unoverridden method
	 * (fetch, store, etc.) falls through to Horde, establish a real connection
	 * on first use so the command can proceed.
	 */
	#[\Override]
	protected function _sendCmd($cmd) {
		if (!$this->hordeConnected) {
			$this->hordeConnected = true;
			$this->logger->info(
				'Horde fallback: opening real IMAP connection for account {id}',
				['id' => $this->accountId]
			);
			// Reset auth state so parent::_login() actually connects.
			$this->_isAuthenticated = false;
			parent::_login();
		}
		return parent::_sendCmd($cmd);
	}

	/**
	 * List mailboxes via the sidecar.
	 *
	 * @param mixed $pattern Mailbox pattern (string or Horde_Imap_Client_Mailbox)
	 * @param int $mode List mode (e.g., Horde_Imap_Client::MBOX_ALL_SUBSCRIBED)
	 * @param array $options List options
	 * @return array Keyed by UTF-8 mailbox name, values have 'mailbox', 'attributes', 'delimiter'
	 */
	#[\Override]
	public function listMailboxes($pattern, $mode = 0, array $options = []) {
		try {
			$response = $this->sidecar->forward(
				'POST',
				'/imap/list',
				[
					'pattern' => ($pattern instanceof Horde_Imap_Client_Mailbox) ? $pattern->utf8 : (string)$pattern,
					'mode' => $this->mapListMode($mode),
				],
				$this->imapCreds,
				$this->accountId,
				$this->email,
			);
		} catch (\Exception $e) {
			$this->logger->warning('Sidecar listMailboxes failed: {error}', ['error' => $e->getMessage()]);
			throw new Horde_Imap_Client_Exception(
				'Sidecar listMailboxes failed: ' . $e->getMessage(),
				Horde_Imap_Client_Exception::SERVER_CONNECT
			);
		}

		$result = [];
		foreach ($response as $name => $entry) {
			$result[$name] = [
				'mailbox' => Horde_Imap_Client_Mailbox::get($entry['mailbox']),
				'attributes' => $entry['attributes'] ?? [],
				'delimiter' => $entry['delimiter'] ?? null,
			];
		}

		if (!empty($options['flat'])) {
			return array_values(array_map(
				fn(array $entry) => $entry['mailbox'],
				$result,
			));
		}

		return $result;
	}

	/**
	 * Get mailbox status via the sidecar.
	 *
	 * @param mixed $mailbox Mailbox name or Horde_Imap_Client_Mailbox
	 * @param int $flags Status data items to fetch
	 * @param array $opts Additional options
	 * @return array Status data (messages, unseen, etc.)
	 */
	// TODO: The Go sidecar already fetches message/unseen counts during LIST-STATUS
	// (single IMAP round trip) but the /imap/list response currently discards them.
	// Each status() call here triggers a separate POST /imap/status → IMAP STATUS,
	// creating N+1 HTTP round trips. Optimize by either including counts in the
	// /imap/list response and caching them here, or adding a combined endpoint.
	// Flags the sidecar can handle — everything else falls through to Horde.
	private const SIDECAR_STATUS_FLAGS = \Horde_Imap_Client::STATUS_MESSAGES
		| \Horde_Imap_Client::STATUS_UNSEEN
		| \Horde_Imap_Client::STATUS_UIDNEXT
		| \Horde_Imap_Client::STATUS_UIDVALIDITY
		| \Horde_Imap_Client::STATUS_HIGHESTMODSEQ;

	#[\Override]
	public function status($mailbox, $flags = 0, array $opts = []) {
		// If caller requests flags we don't handle (SYNCMODSEQ, PERMFLAGS, etc.),
		// fall through to Horde which manages its own internal state for those.
		if (($flags & ~self::SIDECAR_STATUS_FLAGS) !== 0) {
			return parent::status($mailbox, $flags, $opts);
		}

		$mailboxName = ($mailbox instanceof Horde_Imap_Client_Mailbox)
			? $mailbox->utf8
			: (string)$mailbox;

		try {
			$response = $this->sidecar->forward(
				'POST',
				'/imap/status',
				['mailbox' => $mailboxName],
				$this->imapCreds,
				$this->accountId,
				$this->email,
			);
		} catch (\Exception $e) {
			$this->logger->warning('Sidecar status failed for {mailbox}: {error}', [
				'mailbox' => $mailboxName,
				'error' => $e->getMessage(),
			]);
			throw new Horde_Imap_Client_Exception(
				'Sidecar status failed: ' . $e->getMessage(),
				Horde_Imap_Client_Exception::SERVER_CONNECT
			);
		}

		return [
			'messages' => $response['messages'] ?? 0,
			'unseen' => $response['unseen'] ?? 0,
			'uidnext' => $response['uidnext'] ?? 0,
			'uidvalidity' => $response['uidvalidity'] ?? 0,
			'highestmodseq' => $response['highestmodseq'] ?? 0,
		];
	}

	/**
	 * Map Horde list mode constants to sidecar mode strings.
	 */
	private function mapListMode(int $mode): string {
		return match ($mode) {
			\Horde_Imap_Client::MBOX_ALL_SUBSCRIBED => 'all_subscribed',
			\Horde_Imap_Client::MBOX_SUBSCRIBED => 'subscribed',
			\Horde_Imap_Client::MBOX_SUBSCRIBED_EXISTS => 'subscribed_exists',
			\Horde_Imap_Client::MBOX_UNSUBSCRIBED => 'unsubscribed',
			default => 'all',
		};
	}
}
