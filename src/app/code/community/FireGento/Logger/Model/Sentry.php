<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category  FireGento
 * @package   FireGento_Logger
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2013 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */

/**
 * Model for Sentry 3 logging.
 * Requires installing sentry/sdk via composer!
 *
 * @category FireGento
 * @package  FireGento_Logger
 * @author   FireGento Team <team@firegento.com>
 *
 * see: https://github.com/magento-hackathon/LoggerSentry
 */

use Sentry\EventHint;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Severity;
use Sentry\StacktraceBuilder;
use Sentry\State\Scope;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;
use function Sentry\init;

class FireGento_Logger_Model_Sentry extends FireGento_Logger_Model_Abstract
{
    /**
     * @var bool
     */
    protected static $_isInitialized;

    protected $_priorityToLevelMapping = [
        0 /*Zend_Log::EMERG*/  => 'fatal',
        1 /*Zend_Log::ALERT*/  => 'fatal',
        2 /*Zend_Log::CRIT*/   => 'fatal',
        3 /*Zend_Log::ERR*/    => 'error',
        4 /*Zend_Log::WARN*/   => 'warning',
        5 /*Zend_Log::NOTICE*/ => 'info',
        6 /*Zend_Log::INFO*/   => 'info',
        7 /*Zend_Log::DEBUG*/  => 'debug',
    ];

    /** @var string|null */
    protected $_fileName;

    public function __construct($fileName = NULL)
    {
        $this->_fileName = $fileName ? basename($fileName) : NULL;
    }

    /**
     * Init Sentry SDK
     *
     * @return bool
     */
    public function initSentrySdk()
    {
        if (is_null(self::$_isInitialized)) {
            $helper = Mage::helper('firegento_logger');
            $dsn = $helper->getLoggerConfig('sentry/public_dsn');
            if ( ! $dsn) {
                self::$_isInitialized = FALSE;
                return FALSE;
            }

            // Create a new Client and Hub and retrieve the client options
            init([
                'dsn' => $dsn,
                // Disable Sentry's error handler to avoid duplicate logged errors
                'integrations' => static function (array $integrations) {
                    return array_filter($integrations, static function (IntegrationInterface $integration) {
                        return ! $integration instanceof ErrorListenerIntegration;
                    });
                },
            ]);
            $options = SentrySdk::getCurrentHub()->getClient()->getOptions();

            // Set priority level filter
            $priority = $helper->getLoggerConfig('sentry/priority');
            if ($priority === 'default') {
                $priority = $helper->getLoggerConfig('general/priority');
            }
            $level = $this->_priorityToLevelMapping[$priority];
            configureScope(function (Scope $scope) use ($level): void {
                $scope->setLevel(new Severity($level));
            });

            // Strip base path from filenames
            $options->setPrefixes([BP]);

            // Set environment from the configuration (think staging vs prod or similar)
            if ($environment = trim($helper->getLoggerConfig('sentry/environment'))) {
                $options->setEnvironment($environment);
            }

            // Attach stacktrace
            $options->setAttachStacktrace(TRUE);

            // Send personally identifiable information
            $options->setSendDefaultPii(TRUE);

            // Add session data to the user info
            if (function_exists('session_id') && session_id()) {
                configureScope(function (Scope $scope): void {
                    $user = array(
                        'id' => session_id(),
                    );
                    if ( ! empty($_SERVER['REMOTE_ADDR'])) {
                        $user['ip_address'] = $_SERVER['REMOTE_ADDR'];
                    }
                    if ( ! empty($_SESSION)) {
                        $user['data'] = $_SESSION;
                    }
                    $scope->setUser($user);
                });
            }

            // Add the installation code as indexed and searchable tag
            if ($installCode = Mage::helper('mwe')->getInstallCode()) {
                configureScope(function (Scope $scope) use ($installCode): void {
                    $scope->setTag('instance_uid', $installCode);
                });
            }

            self::$_isInitialized = TRUE;
        }

        return !! self::$_isInitialized;
    }

    /**
     * Write a message to the log
     *
     * Sentry has own build-in processing the logs.
     * Nothing to do here.
     *
     * @see FireGento_Logger_Model_Observer::actionPreDispatch()
     *
     * @param FireGento_Logger_Model_Event $event
     * @throws Zend_Log_Exception
     */
    protected function _write($event)
    {
        try {
            Mage::helper('firegento_logger')->addEventMetadata($event, NULL, $this->_enableBacktrace);

            if ( ! $this->initSentrySdk()) {
                return;
            }

            // Get message priority
            if ( ! isset($event['priority']) || $event['priority'] === Zend_Log::ERR ) {
                $this->_assumePriorityByMessage($event);
            }
            $priority = isset($event['priority']) ? $event['priority'] : 3;

            // Add extra data
            $data['extra']['timeElapsed'] = $event->getTimeElapsed();
            if ($event->getAdminUserId()) $data['extra']['adminUserId'] = $event->getAdminUserId();
            if ($event->getAdminUserName()) $data['extra']['adminUserName'] = $event->getAdminUserName();
            if (class_exists('Mage')) {
                if (Mage::registry('logger_data_extra')) {
                    $data['extra'] = array_merge($data['extra'], Mage::registry('logger_data_extra'));
                }
            }

            if ($event->getException()) {
                captureException($event->getException(), EventHint::fromArray($data));
            } else {
                $level = $this->_priorityToLevelMapping[$priority];
                // Prepare EventHint object
                $backtrace = $event->getBacktraceArray() ?: TRUE;
                $eventHint = new EventHint();
                if (is_array($backtrace)) {
                    $options = SentrySdk::getCurrentHub()->getClient()->getOptions();
                    $stacktraceBuilder = new StacktraceBuilder($options, new RepresentationSerializer($options));
                    $eventHint->stacktrace = $stacktraceBuilder->buildFromBacktrace($backtrace, __FILE__, __LINE__);
                }
                // Capture message
                captureMessage($event['message'], new Severity($level), $eventHint);
            }

        } catch (Exception $e) {
            throw new Zend_Log_Exception($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Try to attach a priority # based on the error message string (since sometimes it is not specified).
     *
     * @param $event
     * @return $this
     */
    protected function _assumePriorityByMessage(&$event)
    {
        if (
            stripos($event['message'], "warn") === 0 ||
            stripos($event['message'], "user warn") === 0
        ) {
            $event['priority'] = 4;
        }
        else if (
            stripos($event['message'], "notice") === 0 ||
            stripos($event['message'], "user notice") === 0 ||
            stripos($event['message'], "strict notice") === 0 ||
            stripos($event['message'], "deprecated") === 0
        ) {
            $event['priority'] = 5;
        }

        return $this;
    }
}