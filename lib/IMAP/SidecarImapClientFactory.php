<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ncmail-turbo contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\IMAP;

use Horde_Imap_Client_Socket;
use OCA\Mail\Account;
use OCA\Mail\Cache\HordeCacheFactory;
use OCA\Mail\Events\BeforeImapClientCreated;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\SidecarClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Factory that produces SidecarImapClient instances instead of real Horde clients.
 *
 * Drop-in replacement for IMAPClientFactory — same interface, swapped via DI.
 * Every call to getClient() returns a SidecarImapClient that delegates IMAP
 * operations to the Go sidecar over HTTP.
 */
class SidecarImapClientFactory extends IMAPClientFactory {
	private SidecarClient $sidecar;
	private LoggerInterface $logger;
	private ICrypto $crypto;
	private IConfig $config;

	public function __construct(
		ICrypto $crypto,
		IConfig $config,
		ICacheFactory $cacheFactory,
		IEventDispatcher $eventDispatcher,
		ITimeFactory $timeFactory,
		HordeCacheFactory $hordeCacheFactory,
		SidecarClient $sidecar,
		LoggerInterface $logger,
	) {
		parent::__construct($crypto, $config, $cacheFactory, $eventDispatcher, $timeFactory, $hordeCacheFactory);
		$this->sidecar = $sidecar;
		$this->logger = $logger;
		$this->crypto = $crypto;
		$this->config = $config;
	}

	/**
	 * @throws ServiceException
	 */
	#[\Override]
	public function getClient(Account $account, bool $useCache = true): Horde_Imap_Client_Socket {
		$host = $account->getMailAccount()->getInboundHost();
		$user = $account->getMailAccount()->getInboundUser();
		$port = $account->getMailAccount()->getInboundPort();
		$sslMode = $account->getMailAccount()->getInboundSslMode();

		$decryptedPassword = null;
		if ($account->getMailAccount()->getInboundPassword() !== null) {
			$decryptedPassword = $this->crypto->decrypt($account->getMailAccount()->getInboundPassword());
		}

		// SSL mode for Horde params: 'none' → false
		$hordeSecure = ($sslMode === 'none') ? false : $sslMode;

		$params = [
			'username' => $user,
			'password' => $decryptedPassword,
			'hostspec' => $host,
			'port' => $port,
			'secure' => $hordeSecure,
			'timeout' => (int)$this->config->getSystemValue('app.mail.imap.timeout', 5),
			'context' => [
				'ssl' => [
					'verify_peer' => $this->config->getSystemValueBool('app.mail.verify-tls-peer', true),
					'verify_peer_name' => $this->config->getSystemValueBool('app.mail.verify-tls-peer', true),
				],
			],
		];

		// Map SSL mode to sidecar format
		$sidecarSsl = match ($sslMode) {
			'ssl', 'tls' => 'ssl',
			'starttls' => 'starttls',
			default => 'none',
		};

		$imapCreds = SidecarClient::buildImapCredentials(
			$host,
			(int)$port,
			$user,
			$decryptedPassword ?? '',
			$sidecarSsl,
		);

		return new SidecarImapClient(
			$params,
			$this->sidecar,
			$this->logger,
			$imapCreds,
			$account->getId(),
			$account->getEmail(),
		);
	}
}
