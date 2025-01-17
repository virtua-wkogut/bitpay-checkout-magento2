<?php
namespace Bitpay\BPCheckout\Model;

use Bitpay\BPCheckout\Logger\Logger;
use Bitpay\BPCheckout\Model\Config;
use Bitpay\BPCheckout\Model\Invoice;
use Bitpay\BPCheckout\Model\TransactionRepository;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Bitpay\BPCheckout\Model\Ipn\BPCItem;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\Manager;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\Page;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\App\ResponseFactory;
use Magento\Framework\View\Result\PageFactory;

class BPRedirect
{
    protected $checkoutSession;
    protected $redirect;
    protected $response;
    protected $orderInterface;
    protected $transactionRepository;
    protected $config;
    protected $actionFlag;
    protected $responseFactory;
    protected $invoice;
    protected $messageManager;
    protected $registry;
    protected $url;
    protected $logger;
    protected $resultPageFactory;

    public function __construct(
        Session $checkoutSession,
        ActionFlag $actionFlag,
        RedirectInterface $redirect,
        ResponseInterface $response,
        OrderInterface $orderInterface,
        Config $config,
        TransactionRepository $transactionRepository,
        ResponseFactory $responseFactory,
        Invoice $invoice,
        Manager $messageManager,
        Registry $registry,
        UrlInterface $url,
        Logger $logger,
        PageFactory $resultPageFactory
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->actionFlag = $actionFlag;
        $this->redirect = $redirect;
        $this->response = $response;
        $this->orderInterface = $orderInterface;
        $this->config = $config;
        $this->transactionRepository = $transactionRepository;
        $this->responseFactory = $responseFactory;
        $this->invoice = $invoice;
        $this->messageManager = $messageManager;
        $this->registry = $registry;
        $this->url = $url;
        $this->logger = $logger;
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     * @return Page|void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $orderId = $this->checkoutSession->getData('last_order_id');
        if (!$orderId) {
            $this->response->setRedirect($this->url->getUrl('checkout/cart'))->sendResponse();
            return;
        }

        $order = $this->orderInterface->load($orderId);
        $incrementId = $order->getIncrementId();
        $baseUrl = $this->config->getBaseUrl();
        if ($order->getPayment()->getMethodInstance()->getCode() !== Config::BITPAY_PAYMENT_METHOD_NAME) {
            return $this->resultPageFactory->create();
        }

        try {
            #set to pending and override magento coding
            $this->setToPendingAndOverrideMagentoStatus($order);
            #get the environment
            $env = $this->config->getBitpayEnv();
            $bitpayToken = $this->config->getToken();
            $modal = $this->config->getBitpayUx() === 'modal';
            //create an item, should be passed as an object'

            $redirectUrl = $baseUrl .'bitpay-invoice/?order_id='. $incrementId;
            $params = $this->getParams($order, $incrementId, $modal, $redirectUrl, $baseUrl, $bitpayToken);
            $billingAddressData = $order->getBillingAddress()->getData();
            $this->setSessionCustomerData($billingAddressData, $order->getCustomerEmail(), $incrementId);
            $item = new BPCItem($bitpayToken, $params, $env);
            //this creates the invoice with all of the config params from the item
            $invoice = $this->invoice->BPCCreateInvoice($item);
            //now we have to append the invoice transaction id for the callback verification
            $invoiceID = $invoice['data']['id'];
            #insert into the database
            $this->transactionRepository->add($incrementId, $invoiceID, 'new');
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            $this->registry->register('isSecureArea', 'true');
            $order->delete();
            $this->registry->unregister('isSecureArea');
            $this->messageManager->addErrorMessage('We are unable to place your Order at this time');
            $this->responseFactory->create()->setRedirect($this->url->getUrl('checkout/cart'))->sendResponse();

            return;
        }

        switch ($modal) {
            case true:
            case 1:
                #set some info for guest checkout
                $this->setSessionCustomerData($billingAddressData, $order->getCustomerEmail(), $incrementId);
                $RedirectUrl = $baseUrl . 'bitpay-invoice/?invoiceID='.$invoiceID.'&order_id='.$incrementId.'&m=1';
                $this->responseFactory->create()->setRedirect($RedirectUrl)->sendResponse();
                break;
            case false:
            default:
                $this->redirect->redirect($this->response, $invoice['data']['url']);
                break;
        }
    } //end execute function

    private function setSessionCustomerData(array $billingAddressData, string $email, string $incrementId): void
    {
        $this->checkoutSession->setCustomerInfo(
            [
                'billingAddress' => $billingAddressData,
                'email' => $email,
                'incrementId' => $incrementId
            ]
        );
    }

    /**
     * @param OrderInterface $order
     * @return void
     * @throws \Exception
     */
    private function setToPendingAndOverrideMagentoStatus(OrderInterface $order): void
    {
        $order->setState('new', true);
        $order_status = $this->config->getBPCheckoutOrderStatus();
        $order_status = !isset($order_status) ? 'pending' : $order_status;
        $order->setStatus($order_status, true);
        $order->save();
    }

    /**
     * @param OrderInterface $order
     * @param string|null $incrementId
     * @param bool $modal
     * @param string $redirectUrl
     * @param string $baseUrl
     * @param string|null $bitpayToken
     * @return DataObject
     */
    private function getParams(
        OrderInterface $order,
        ?string $incrementId,
        bool $modal,
        string $redirectUrl,
        string $baseUrl,
        ?string $bitpayToken
    ): DataObject {
        $buyerInfo = new DataObject([
            'name' => $order->getBillingAddress()->getFirstName() . ' ' . $order->getBillingAddress()->getLastName(),
            'email' => $order->getCustomerEmail()
        ]);
        return new DataObject([
            'extension_version' => Config::EXTENSION_VERSION,
            'price' => $order['base_grand_total'],
            'currency' => $order['base_currency_code'],
            'buyer' => $buyerInfo->getData(),
            'orderId' => trim($incrementId),
            'redirectURL' => !$modal ? $redirectUrl . "&m=0" : $redirectUrl,
            'notificationURL' => $baseUrl . 'rest/V1/bitpay-bpcheckout/ipn',
            'closeURL' => $baseUrl . 'rest/V1/bitpay-bpcheckout/close?orderID=' . $incrementId,
            'extendedNotifications' => true,
            'token' => $bitpayToken
        ]);
    }
}
