<?php
/*
 * Copyright (C) 2020 Paymentsense Ltd.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author      Paymentsense
 * @copyright   2020 Paymentsense Ltd.
 * @license     https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Paymentsense\Payments\Model\Method;

use Paymentsense\Payments\Model\Psgw\TransactionStatus;
use Paymentsense\Payments\Model\Psgw\TransactionResultCode;
use Paymentsense\Payments\Model\Psgw\HpfResponses;
use Paymentsense\Payments\Model\Traits\BaseInfoMethod;
use Magento\Sales\Model\Order;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Hosted payment method model
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Hosted extends \Magento\Payment\Model\Method\AbstractMethod
{
    use BaseInfoMethod;

    const CODE = 'paymentsense_hosted';

    /**
     * Request Types
     */
    const REQ_NOTIFICATION      = '0';
    const REQ_CUSTOMER_REDIRECT = '1';

    protected $_code                    = self::CODE;
    protected $_canOrder                = true;
    protected $_isGateway               = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canCancelInvoice        = true;
    protected $_canVoid                 = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canAuthorize            = true;
    protected $_isInitializeNeeded      = false;
    protected $_canUseCheckout          = true;
    protected $_canUseInternal          = false;

    /**
     * @var \Paymentsense\Payments\Helper\DiagnosticMessage
     */
    protected $_messageHelper;

    /**
     * @var OrderSender
     */
    protected $_orderSender;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $moduleReader;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\App\Action\Context $actionContext
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Paymentsense\Payments\Helper\Data $moduleHelper
     * @param \Paymentsense\Payments\Helper\IsoCodes $isoCodes
     * @param \Paymentsense\Payments\Helper\DiagnosticMessage $messageHelper
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface
     * @param \Magento\Framework\Module\Dir\Reader $moduleReader
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\App\Action\Context $actionContext,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Session $checkoutSession,
        \Paymentsense\Payments\Helper\Data $moduleHelper,
        \Paymentsense\Payments\Helper\IsoCodes $isoCodes,
        \Paymentsense\Payments\Helper\DiagnosticMessage $messageHelper,
        OrderSender $orderSender,
        \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface,
        \Magento\Framework\Module\Dir\Reader $moduleReader,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $logger = $this->createLogger();
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_actionContext   = $actionContext;
        $this->_storeManager    = $storeManager;
        $this->_checkoutSession = $checkoutSession;
        $this->_moduleHelper    = $moduleHelper;
        $this->_isoCodes        = $isoCodes;
        $this->_orderSender     = $orderSender;
        $this->_configHelper    = $this->getModuleHelper()->getMethodConfig($this->getCode());
        $this->_messageHelper   = $messageHelper;
        $this->productMetadata  = $productMetadataInterface;
        $this->moduleReader     = $moduleReader;

        $this->configureCrossRefTxnAvailability();
    }

    /**
     * Gets the logger
     *
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger->getLogger();
    }

    /**
     * Gets the payment action on payment complete
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        return \Magento\Payment\Model\Method\AbstractMethod::ACTION_ORDER;
    }

    /**
     * Determines method availability
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->getConfigHelper()->isMethodAvailable($this->getCode());
    }

    /**
     * Order Payment
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     */
    // @codingStandardsIgnoreLine
    public function order(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->getLogger()->info('ACTION_ORDER has been triggered.');
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $order->setState(Order::STATE_NEW);
        $this->getLogger()->info(
            sprintf(
                'New order #%s with amount %.2f %s (%.2f %s) has been created.',
                $order->getRealOrderId(),
                $order->getBaseTotalDue(),
                $order->getBaseCurrencyCode(),
                $order->getTotalDue(),
                $order->getOrderCurrencyCode()
            )
        );
        return $this;
    }

    /**
     * Gets the transaction status and message received by the Hosted Payment Form
     *
     * @param string $requestType Type of the request (notification or customer redirect)
     * @param array $data POST/GET data received with the request from the payment gateway
     * @return array
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity
    public function getTrxStatusAndMessage($requestType, $data)
    {
        $message   = '';
        $trxStatus = TransactionStatus::INVALID;
        if ($this->isHashDigestValid($requestType, $data)) {
            $message = $data['Message'];
            switch ($data['StatusCode']) {
                case TransactionResultCode::SUCCESS:
                    $trxStatus = TransactionStatus::SUCCESS;
                    break;
                case TransactionResultCode::DUPLICATE:
                    if (TransactionResultCode::SUCCESS === $data['PreviousStatusCode']) {
                        if (array_key_exists('PreviousMessage', $data)) {
                            $message = $data['PreviousMessage'];
                        }
                        $trxStatus = TransactionStatus::SUCCESS;
                    } else {
                        $trxStatus = TransactionStatus::FAILED;
                    }
                    break;
                case TransactionResultCode::REFERRED:
                case TransactionResultCode::DECLINED:
                case TransactionResultCode::FAILED:
                    $trxStatus = TransactionStatus::FAILED;
                    break;
            }
            $this->getLogger()->info(
                'Card details transaction ' . $data['CrossReference'] .
                ' has been performed with status code "' . $data['StatusCode'] . '".'
            );
        } else {
            $this->getLogger()->warning('Callback request with invalid hash digest has been received.');
        }

        return [
            'TrxStatus' => $trxStatus,
            'Message'   => $message
        ];
    }

    /**
     * Gets the transaction status and message from an Order
     *
     * @param string $gatewayOrderId Gateway order ID
     * @return array
     */
    public function loadTrxStatusAndMessage($gatewayOrderId)
    {
        $trxStatus = TransactionStatus::INVALID;
        $message   = '';

        if (isset($gatewayOrderId)) {
            $order = $this->getOrder($gatewayOrderId);
            if ($order) {
                foreach ($order->getStatusHistoryCollection() as $_item) {
                    $orderStatus = $_item->getStatus();
                    $trxStatus =  ($orderStatus === Order::STATE_PROCESSING)
                        ? TransactionStatus::SUCCESS
                        : TransactionStatus::FAILED;
                    if ($_item->getComment()) {
                        $message = $_item->getComment();
                    }

                    break;
                }
            }
        }

        return [
            'TrxStatus' => $trxStatus,
            'Message'   => $message
        ];
    }

    /**
     * Checks whether the hash digest received from the payment gateway is valid
     *
     * @param string $requestType Type of the request (notification or customer redirect)
     * @param array $data POST/GET data received with the request from the payment gateway
     * @return bool
     */
    public function isHashDigestValid($requestType, $data)
    {
        $config = $this->getConfigHelper();
        $result = false;
        $dataString   = $this->buildPostString($requestType, $data);
        if ($dataString) {
            $hashDigestReceived   = $data['HashDigest'];
            $hashDigestCalculated = $this->calculateHashDigest(
                $dataString,
                $config->getHashMethod(),
                $config->getPresharedKey()
            );
            $result = strToUpper($hashDigestReceived) === strToUpper($hashDigestCalculated);
        }
        return $result;
    }

    /**
     * Builds a string containing the expected fields from the request received from the payment gateway
     *
     * @param string $requestType Type of the request (notification or customer redirect)
     * @param array $data POST/GET data received with the request from the payment gateway
     * @return bool
     */
    public function buildPostString($requestType, $data)
    {
        $result = false;
        $fields = [
            // Variables for hash digest calculation for notification requests (excluding configuration variables)
            self::REQ_NOTIFICATION      => [
                'StatusCode',
                'Message',
                'PreviousStatusCode',
                'PreviousMessage',
                'CrossReference',
                'Amount',
                'CurrencyCode',
                'OrderID',
                'TransactionType',
                'TransactionDateTime',
                'OrderDescription',
                'CustomerName',
                'Address1',
                'Address2',
                'Address3',
                'Address4',
                'City',
                'State',
                'PostCode',
                'CountryCode',
                'EmailAddress',
                'PhoneNumber'
            ],
            // Variables for hash digest calculation for customer redirects (excluding configuration variables)
            self::REQ_CUSTOMER_REDIRECT => [
                'CrossReference',
                'OrderID',
            ],
        ];

        $config = $this->getConfigHelper();
        if (array_key_exists($requestType, $fields)) {
            $result = 'MerchantID=' . $config->getMerchantId() . '&Password=' . $config->getPassword();
            foreach ($fields[$requestType] as $field) {
                $result .= '&' . $field . '=' . str_replace('&amp;', '&', $data[$field]);
            }
        }

        return $result;
    }

    /**
     * Gets the gateway settings message
     *
     * @param bool $textFormat Specifies whether the format of the message is text
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity
    public function getSettingsMessage($textFormat)
    {
        $result = [];
        try {
            $merchantIdFormatValid = $this->getModuleHelper()->isMerchantIdFormatValid($this->getCode());
        } catch (\Exception $e) {
            $merchantIdFormatValid = false;
        }
        if (! $merchantIdFormatValid) {
            $result = $this->_messageHelper->buildErrorSettingsMessage(
                __(
                    'Gateway MerchantID is invalid. '
                    . 'Please make sure the Gateway MerchantID matches the ABCDEF-1234567 format.'
                )
            );
        } else {
            $ggepResult = $this->performGetGatewayEntryPointsTxn();
            $hpfResult  = $this->checkGatewaySettings();

            $merchantCredentialsValid = null;
            $trxStatusCode = $ggepResult['StatusCode'];
            if (TransactionResultCode::SUCCESS === $trxStatusCode) {
                $merchantCredentialsValid = true;
            } elseif (TransactionResultCode::FAILED === $trxStatusCode) {
                if ($this->merchantCredentialsInvalid($ggepResult['Message'])) {
                    $merchantCredentialsValid = false;
                }
            }
            switch ($hpfResult) {
                case HpfResponses::HPF_RESP_OK:
                    $result = $this->_messageHelper->buildSuccessSettingsMessage(
                        __(
                            'Gateway MerchantID, Gateway Password, '
                            . 'Gateway PreSharedKey and Gateway Hash Method are valid.'
                        )
                    );
                    break;
                case HpfResponses::HPF_RESP_MID_MISSING:
                case HpfResponses::HPF_RESP_MID_NOT_EXISTS:
                    $result = $this->_messageHelper->buildErrorSettingsMessage(
                        __(
                            'Gateway MerchantID is invalid.'
                        )
                    );
                    break;
                case HpfResponses::HPF_RESP_HASH_INVALID:
                    if (true === $merchantCredentialsValid) {
                        $result = $this->_messageHelper->buildErrorSettingsMessage(
                            __(
                                'Gateway PreSharedKey or/and Gateway Hash Method are invalid.'
                            )
                        );
                    } elseif (false === $merchantCredentialsValid) {
                        $result = $this->_messageHelper->buildErrorSettingsMessage(
                            __(
                                'Gateway Password is invalid.'
                            )
                        );
                    } else {
                        $result = $this->_messageHelper->buildErrorSettingsMessage(
                            __(
                                'Gateway Password, Gateway PreSharedKey or/and Gateway Hash Method are invalid.'
                            )
                        );
                    }
                    break;
                case HpfResponses::HPF_RESP_NO_RESPONSE:
                    if (true === $merchantCredentialsValid) {
                        $result = $this->_messageHelper->buildWarningSettingsMessage(
                            __(
                                'Gateway PreSharedKey and Gateway Hash Method cannot be validated at this time.'
                            )
                        );
                    } elseif (false === $merchantCredentialsValid) {
                        $result = $this->_messageHelper->buildErrorSettingsMessage(
                            __(
                                'Gateway MerchantID or/and Gateway Password are invalid.'
                            )
                        );
                    } else {
                        $result = $this->_messageHelper->buildWarningSettingsMessage(
                            __(
                                'The gateway settings cannot be validated at this time.'
                            )
                        );
                    }
                    break;
            }
        }

        if ($textFormat) {
            $result = $this->_messageHelper->getSettingsTextMessage($result);
        }

        return $result;
    }
}
