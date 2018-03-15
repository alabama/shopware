<?php declare(strict_types=1);

namespace Shopware\Storefront\Controller;

use Ramsey\Uuid\Uuid;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Shopware\Api\Entity\Search\Criteria;
use Shopware\Api\Entity\Search\Query\TermQuery;
use Shopware\Api\Order\Repository\OrderRepository;
use Shopware\Api\Order\Struct\OrderBasicStruct;
use Shopware\Api\Payment\Repository\PaymentMethodRepository;
use Shopware\CartBridge\Service\StoreFrontCartService;
use Shopware\Context\Struct\ShopContext;
use Shopware\Context\Struct\StorefrontContext;
use Shopware\Payment\Exception\InvalidOrderException;
use Shopware\Payment\Exception\InvalidTransactionException;
use Shopware\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Payment\PaymentHandler\PaymentHandlerInterface;
use Shopware\Payment\PaymentProcessor;
use Shopware\Payment\Token\PaymentTransactionTokenFactory;
use Shopware\Storefront\Context\StorefrontContextService;
use Shopware\Storefront\Page\Checkout\PaymentMethodLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController extends StorefrontController
{
    /**
     * @var StoreFrontCartService
     */
    private $cartService;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var PaymentMethodLoader
     */
    private $paymentMethodLoader;

    /**
     * @var PaymentProcessor
     */
    private $paymentProcessor;

    /**
     * @var PaymentMethodRepository
     */
    private $paymentMethodRepository;

    /**
     * @var PaymentTransactionTokenFactory
     */
    private $tokenFactory;

    public function __construct(
        StoreFrontCartService $cartService,
        OrderRepository $orderRepository,
        PaymentMethodLoader $paymentMethodLoader,
        PaymentProcessor $paymentProcessor,
        PaymentTransactionTokenFactory $tokenFactory,
        PaymentMethodRepository $paymentMethodRepository
    ) {
        $this->cartService = $cartService;
        $this->orderRepository = $orderRepository;
        $this->paymentMethodLoader = $paymentMethodLoader;
        $this->paymentProcessor = $paymentProcessor;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->tokenFactory = $tokenFactory;
    }

    /**
     * @Route("/checkout", name="checkout_index", options={"seo"="false"})
     */
    public function indexAction()
    {
        return $this->redirectToRoute('checkout_cart');
    }

    /**
     * @Route("/checkout/cart", name="checkout_cart", options={"seo"="false"})
     */
    public function cartAction(): Response
    {
        return $this->renderStorefront('@Storefront/frontend/checkout/cart.html.twig', [
            'cart' => $this->cartService->getCalculatedCart(),
        ]);
    }

    /**
     * @Route("/checkout/shippingPayment", name="checkout_shipping_payment", options={"seo"="false"})
     */
    public function shippingPaymentAction(Request $request, StorefrontContext $context): Response
    {
        return $this->renderStorefront('@Storefront/frontend/checkout/shipping_payment.html.twig', [
            'paymentMethods' => $this->paymentMethodLoader->load($request, $context->getShopContext()),
        ]);
    }

    /**
     * @Route("/checkout/saveShippingPayment", name="checkout_save_shipping_payment", options={"seo"="false"})
     * @Method({"POST"})
     *
     * @throws UnknownPaymentMethodException
     */
    public function saveShippingPaymentAction(Request $request, StorefrontContext $context): Response
    {
        $paymentMethodId = (string) $request->request->get('paymentMethodId', '');
        if (!Uuid::isValid($paymentMethodId)) {
            throw new UnknownPaymentMethodException(sprintf('Unknown payment method with with id %s', $paymentMethodId));
        }

        $request->getSession()->set(StorefrontContextService::SESSION_PAYMENT_METHOD_ID, $paymentMethodId);

        // todo validate, process and store custom template data
        return $this->redirectToRoute('checkout_confirm');
    }

    /**
     * @Route("/checkout/confirm", name="checkout_confirm", options={"seo"="false"})
     *
     * @param StorefrontContext $context
     *
     * @return RedirectResponse|Response
     */
    public function confirmAction(StorefrontContext $context): Response
    {
        if (!$context->getCustomer()) {
            return $this->redirectToRoute('account_login');
        }
        if ($this->cartService->getCalculatedCart()->getCalculatedLineItems()->count() === 0) {
            return $this->redirectToRoute('checkout_cart');
        }

        return $this->renderStorefront('@Storefront/frontend/checkout/confirm.html.twig', [
            'cart' => $this->cartService->getCalculatedCart(),
        ]);
    }

    /**
     * @Route("/checkout/pay", name="checkout_pay", options={"seo"="false"})
     *
     * @param Request $request
     * @param StorefrontContext $context
     *
     * @return RedirectResponse
     *
     * @throws InvalidOrderException
     * @throws InvalidTransactionException
     * @throws UnknownPaymentMethodException
     */
    public function payAction(Request $request, StorefrontContext $context): RedirectResponse
    {
        $shopContext = $context->getShopContext();
        if (!$context->getCustomer()) {
            return $this->redirectToRoute('account_login');
        }

        $transaction = $request->get('transaction');

        //check if customer is inside transaction loop
        if ($transaction && Uuid::isValid($transaction)) {
            $orderId = $this->getOrderIdByTransactionId($transaction, $context);

            return $this->processPayment($orderId, $shopContext);
        }

        //customer is not inside transaction loop and tries to finish the order
        if ($this->cartService->getCalculatedCart()->getCalculatedLineItems()->count() === 0) {
            return $this->redirectToRoute('checkout_cart');
        }

        //save order and start transaction loop
        $orderId = $this->cartService->order();

        return $this->processPayment($orderId, $shopContext);
    }


    /**
     * @Route("/checkout/finalize-transaction", name="checkout_finalize_transaction", options={"seo"="false"})
     *
     * @param Request $request
     * @param StorefrontContext $context
     *
     * @return RedirectResponse
     *
     * @throws UnknownPaymentMethodException
     * @throws \Doctrine\DBAL\Exception\InvalidArgumentException
     * @throws \Shopware\Payment\Exception\InvalidTokenException
     * @throws \Shopware\Payment\Exception\TokenExpiredException
     */
    public function finalizeTransactionAction(Request $request, StorefrontContext $context): RedirectResponse
    {
        $paymentToken = $this->tokenFactory->validateToken($request->get('token'));

        $this->tokenFactory->invalidateToken($paymentToken->getToken());

        $paymentHandler = $this->getPaymentHandlerById($paymentToken->getPaymentMethodId(), $context->getShopContext());

        $paymentHandler->finalize($paymentToken->getTransactionId(), $request, $context->getShopContext());

        return $this->redirectToRoute('checkout_pay', ['transaction' => $paymentToken->getTransactionId()]);
    }

    /**
     * @Route("/checkout/finish", name="checkout_finish", options={"seo"="false"})
     * @Method({"GET"})
     *
     * @throws \Exception
     */
    public function finishAction(Request $request, StorefrontContext $context): Response
    {
        $order = $this->getOrder($request->get('order'), $context);

        $calculatedCart = $this->get('Shopware\Framework\Serializer\StructNormalizer')
            ->denormalize(json_decode($order->getPayload(), true));

        return $this->renderStorefront('@Storefront/frontend/checkout/finish.html.twig', [
            'cart' => $calculatedCart,
            'customer' => $context->getCustomer(),
        ]);
    }

    private function getOrder(string $orderId, StorefrontContext $context): OrderBasicStruct
    {
        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('order.customer.id', $context->getCustomer()->getId()));
        $criteria->addFilter(new TermQuery('order.id', $orderId));

        $searchResult = $this->orderRepository->search($criteria, $context->getShopContext());

        if ($searchResult->count() !== 1) {
            throw new \Exception(sprintf('Unable to find order with id: %s', $orderId));
        }

        return $searchResult->first();
    }

    private function getOrderIdByTransactionId(string $transactionId, StorefrontContext $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('order.customer.id', $context->getCustomer()->getId()));
        $criteria->addFilter(new TermQuery('order.transactions.id', $transactionId));

        $searchResult = $this->orderRepository->searchIds($criteria, $context->getShopContext());

        if ($searchResult->getTotal() !== 1) {
            throw new InvalidTransactionException($transactionId);
        }

        $ids = $searchResult->getIds();
        return array_shift($ids);
    }

    private function processPayment(string $orderId, ShopContext $shopContext): RedirectResponse
    {
        $redirect = $this->paymentProcessor->process($orderId, $shopContext);

        if ($redirect) {
            return $redirect;
        }

        return $this->redirectToRoute('checkout_finish', ['order' => $orderId]);
    }

    private function getPaymentHandlerById(string $paymentMethodId, ShopContext $context): PaymentHandlerInterface
    {
        $paymentMethods = $this->paymentMethodRepository->readBasic([$paymentMethodId], $context);

        $paymentMethod = $paymentMethods->get($paymentMethodId);
        if (!$paymentMethod) {
            throw new UnknownPaymentMethodException($paymentMethodId);
        }

        try {
            return $this->container->get($paymentMethod->getClass());
        } catch (NotFoundExceptionInterface $e) {
            throw new UnknownPaymentMethodException($paymentMethod->getClass());
        }
    }
}
