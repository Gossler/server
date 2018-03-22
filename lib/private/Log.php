<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Johannes Schlichenmaier <johannes@schlichenmaier.info>
 * @author Juan Pablo Villafáñez <jvillafanez@solidgear.es>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Olivier Paroz <github@oparoz.com>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Thomas Pulzer <t.pulzer@kniel.de>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC;

use InterfaSys\LogNormalizer\Normalizer;

use OC\Log\File;
use OCP\ILogger;
use OCP\Support\CrashReport\IRegistry;
use OCP\Util;

/**
 * logging utilities
 *
 * This is a stand in, this should be replaced by a Psr\Log\LoggerInterface
 * compatible logger. See https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
 * for the full interface specification.
 *
 * MonoLog is an example implementing this interface.
 */
class Log implements ILogger {

	/** @var string */
	private $logger;

	/** @var SystemConfig */
	private $config;

	/** @var boolean|null cache the result of the log condition check for the request */
	private $logConditionSatisfied = null;

	/** @var Normalizer */
	private $normalizer;

	/** @var IRegistry */
	private $crashReporters;

	protected $methodsWithSensitiveParameters = [
		// Session/User
		'completeLogin',
		'login',
		'checkPassword',
		'checkPasswordNoLogging',
		'loginWithPassword',
		'updatePrivateKeyPassword',
		'validateUserPass',
		'loginWithToken',
		'{closure}',

		// TokenProvider
		'getToken',
		'isTokenPassword',
		'getPassword',
		'decryptPassword',
		'logClientIn',
		'generateToken',
		'validateToken',

		// TwoFactorAuth
		'solveChallenge',
		'verifyChallenge',

		// ICrypto
		'calculateHMAC',
		'encrypt',
		'decrypt',

		// LoginController
		'tryLogin',
		'confirmPassword',

		// LDAP
		'bind',
		'areCredentialsValid',
		'invokeLDAPMethod',

		// Encryption
		'storeKeyPair',
		'setupUser',
	];

	/**
	 * @param string $logger The logger that should be used
	 * @param SystemConfig $config the system config object
	 * @param Normalizer|null $normalizer
	 * @param IRegistry|null $registry
	 */
	public function __construct($logger = null, SystemConfig $config = null, $normalizer = null, IRegistry $registry = null) {
		// FIXME: Add this for backwards compatibility, should be fixed at some point probably
		if ($config === null) {
			$config = \OC::$server->getSystemConfig();
		}

		$this->config = $config;

		// FIXME: Add this for backwards compatibility, should be fixed at some point probably
		if ($logger === null) {
			$logType = $this->config->getValue('log_type', 'file');
			$this->logger = static::getLogClass($logType);
			call_user_func([$this->logger, 'init']);
		} else {
			$this->logger = $logger;
		}
		if ($normalizer === null) {
			$this->normalizer = new Normalizer();
		} else {
			$this->normalizer = $normalizer;
		}
		$this->crashReporters = $registry;
	}

	/**
	 * System is unusable.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function emergency(string $message, array $context = []) {
		$this->log(Util::FATAL, $message, $context);
	}

	/**
	 * Action must be taken immediately.
	 *
	 * Example: Entire website down, database unavailable, etc. This should
	 * trigger the SMS alerts and wake you up.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function alert(string $message, array $context = []) {
		$this->log(Util::ERROR, $message, $context);
	}

	/**
	 * Critical conditions.
	 *
	 * Example: Application component unavailable, unexpected exception.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function critical(string $message, array $context = []) {
		$this->log(Util::ERROR, $message, $context);
	}

	/**
	 * Runtime errors that do not require immediate action but should typically
	 * be logged and monitored.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function error(string $message, array $context = []) {
		$this->log(Util::ERROR, $message, $context);
	}

	/**
	 * Exceptional occurrences that are not errors.
	 *
	 * Example: Use of deprecated APIs, poor use of an API, undesirable things
	 * that are not necessarily wrong.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function warning(string $message, array $context = []) {
		$this->log(Util::WARN, $message, $context);
	}

	/**
	 * Normal but significant events.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function notice(string $message, array $context = []) {
		$this->log(Util::INFO, $message, $context);
	}

	/**
	 * Interesting events.
	 *
	 * Example: User logs in, SQL logs.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function info(string $message, array $context = []) {
		$this->log(Util::INFO, $message, $context);
	}

	/**
	 * Detailed debug information.
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function debug(string $message, array $context = []) {
		$this->log(Util::DEBUG, $message, $context);
	}


	/**
	 * Logs with an arbitrary level.
	 *
	 * @param int $level
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function log(int $level, string $message, array $context = []) {
		$minLevel = $this->getLogLevel($context);

		array_walk($context, [$this->normalizer, 'format']);

		$app = $context['app'] ?? 'no app in context';

		// interpolate $message as defined in PSR-3
		$replace = [];
		foreach ($context as $key => $val) {
			$replace['{' . $key . '}'] = $val;
		}
		$message = strtr($message, $replace);

		if ($level >= $minLevel) {
			call_user_func([$this->logger, 'write'], $app, $message, $level);
		}
	}

	private function getLogLevel($context) {
		/**
		 * check for a special log condition - this enables an increased log on
		 * a per request/user base
		 */
		if ($this->logConditionSatisfied === null) {
			// default to false to just process this once per request
			$this->logConditionSatisfied = false;
			if (!empty($logCondition)) {

				// check for secret token in the request
				if (isset($logCondition['shared_secret'])) {
					$request = \OC::$server->getRequest();

					// if token is found in the request change set the log condition to satisfied
					if ($request && hash_equals($logCondition['shared_secret'], $request->getParam('log_secret', ''))) {
						$this->logConditionSatisfied = true;
					}
				}

				// check for user
				if (isset($logCondition['users'])) {
					$user = \OC::$server->getUserSession()->getUser();

					// if the user matches set the log condition to satisfied
					if ($user !== null && in_array($user->getUID(), $logCondition['users'], true)) {
						$this->logConditionSatisfied = true;
					}
				}
			}
		}

		// if log condition is satisfied change the required log level to DEBUG
		if ($this->logConditionSatisfied) {
			return Util::DEBUG;
		}

		if (isset($context['app'])) {
			$logCondition = $this->config->getValue('log.condition', []);
			$app = $context['app'];

			/**
			 * check log condition based on the context of each log message
			 * once this is met -> change the required log level to debug
			 */
			if (!empty($logCondition)
				&& isset($logCondition['apps'])
				&& in_array($app, $logCondition['apps'], true)) {
				return Util::DEBUG;
			}
		}

		return min($this->config->getValue('loglevel', Util::WARN), Util::FATAL);
	}

	private function filterTrace(array $trace) {
		$sensitiveValues = [];
		$trace = array_map(function (array $traceLine) use (&$sensitiveValues) {
			foreach ($this->methodsWithSensitiveParameters as $sensitiveMethod) {
				if (strpos($traceLine['function'], $sensitiveMethod) !== false) {
					$sensitiveValues = array_merge($sensitiveValues, $traceLine['args']);
					$traceLine['args'] = ['*** sensitive parameters replaced ***'];
					return $traceLine;
				}
			}
			return $traceLine;
		}, $trace);
		return array_map(function (array $traceLine) use ($sensitiveValues) {
			$traceLine['args'] = $this->removeValuesFromArgs($traceLine['args'], $sensitiveValues);
			return $traceLine;
		}, $trace);
	}

	private function removeValuesFromArgs($args, $values) {
		foreach($args as &$arg) {
			if (in_array($arg, $values, true)) {
				$arg = '*** sensitive parameter replaced ***';
			} else if (is_array($arg)) {
				$arg = $this->removeValuesFromArgs($arg, $values);
			}
		}
		return $args;
	}

	/**
	 * Logs an exception very detailed
	 *
	 * @param \Exception|\Throwable $exception
	 * @param array $context
	 * @return void
	 * @since 8.2.0
	 */
	public function logException(\Throwable $exception, array $context = []) {
		$app = $context['app'] ?? 'no app in context';
		$level = $context['level'] ?? Util::ERROR;

		$data = [
			'CustomMessage' => $context['message'] ?? '--',
			'Exception' => get_class($exception),
			'Message' => $exception->getMessage(),
			'Code' => $exception->getCode(),
			'Trace' => $this->filterTrace($exception->getTrace()),
			'File' => $exception->getFile(),
			'Line' => $exception->getLine(),
		];
		if ($exception instanceof HintException) {
			$data['Hint'] = $exception->getHint();
		}

		$minLevel = $this->getLogLevel($context);

		array_walk($context, [$this->normalizer, 'format']);

		if ($level >= $minLevel) {
			if ($this->logger === File::class) {
				call_user_func([$this->logger, 'write'], $app, $data, $level);
			} else {
				$entry = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR);
				call_user_func([$this->logger, 'write'], $app, $entry, $level);
			}
		}

		$context['level'] = $level;
		if (!is_null($this->crashReporters)) {
			$this->crashReporters->delegateReport($exception, $context);
		}
	}

	/**
	 * @param string $logType
	 * @return string
	 * @internal
	 */
	public static function getLogClass(string $logType): string {
		switch (strtolower($logType)) {
			case 'errorlog':
				return \OC\Log\Errorlog::class;
			case 'syslog':
				return \OC\Log\Syslog::class;
			case 'file':
				return \OC\Log\File::class;

			// Backwards compatibility for old and fallback for unknown log types
			case 'owncloud':
			case 'nextcloud':
			default:
				return \OC\Log\File::class;
		}
	}
}
