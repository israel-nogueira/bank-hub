<?php

namespace IsraelNogueira\PaymentHub\Tests\Fixtures;

use IsraelNogueira\PaymentHub\DataObjects\Requests\PixPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CreditCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\DebitCardPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\BoletoPaymentRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\SubscriptionRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\RefundRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\CustomerRequest;
use IsraelNogueira\PaymentHub\DataObjects\Requests\PaymentLinkRequest;

/**
 * Fixtures reutilizáveis para testes
 * 
 * Centraliza a criação de objetos de teste para evitar duplicação
 */
class RequestFixtures
{
    /**
     * Cria um PixPaymentRequest válido para testes
     */
    public static function createPixPaymentRequest(array $overrides = []): PixPaymentRequest
    {
        $defaults = [
            'amount' => 100.00,
            'customerEmail' => 'test@example.com',
            'customerName' => 'Test User',
            'customerDocument' => '12345678909',
            'description' => 'Test PIX Payment',
        ];

        $data = array_merge($defaults, $overrides);

        return PixPaymentRequest::create(
            amount: $data['amount'],
            customerEmail: $data['customerEmail'],
            customerName: $data['customerName'] ?? null,
            customerDocument: $data['customerDocument'] ?? null,
            description: $data['description'] ?? null
        );
    }

    /**
     * Cria um CreditCardPaymentRequest válido para testes
     */
    public static function createCreditCardPaymentRequest(array $overrides = []): CreditCardPaymentRequest
    {
        $defaults = [
            'amount' => 250.00,
            'cardNumber' => '4111111111111111',
            'cardHolderName' => 'TEST USER',
            'cardExpiryMonth' => '12',
            'cardExpiryYear' => '2028',
            'cardCvv' => '123',
            'customerEmail' => 'test@example.com',
            'installments' => 1,
            'capture' => true,
        ];

        $data = array_merge($defaults, $overrides);

        return CreditCardPaymentRequest::create(
            amount: $data['amount'],
            cardNumber: $data['cardNumber'],
            cardHolderName: $data['cardHolderName'],
            cardExpiryMonth: $data['cardExpiryMonth'],
            cardExpiryYear: $data['cardExpiryYear'],
            cardCvv: $data['cardCvv'],
            customerEmail: $data['customerEmail'],
            installments: $data['installments'],
            capture: $data['capture']
        );
    }

    /**
     * Cria um DebitCardPaymentRequest válido para testes
     */
    public static function createDebitCardPaymentRequest(array $overrides = []): DebitCardPaymentRequest
    {
        $defaults = [
            'amount' => 150.00,
            'cardNumber' => '4111111111111111',
            'cardHolderName' => 'TEST USER',
            'cardExpiryMonth' => '12',
            'cardExpiryYear' => '2028',
            'cardCvv' => '123',
            'customerEmail' => 'test@example.com',
        ];

        $data = array_merge($defaults, $overrides);

        return DebitCardPaymentRequest::create(
            amount: $data['amount'],
            cardNumber: $data['cardNumber'],
            cardHolderName: $data['cardHolderName'],
            cardExpiryMonth: $data['cardExpiryMonth'],
            cardExpiryYear: $data['cardExpiryYear'],
            cardCvv: $data['cardCvv'],
            customerEmail: $data['customerEmail']
        );
    }

    /**
     * Cria um BoletoPaymentRequest válido para testes
     */
    public static function createBoletoPaymentRequest(array $overrides = []): BoletoPaymentRequest
    {
        $defaults = [
            'amount' => 500.00,
            'customerName' => 'Test User',
            'customerDocument' => '12345678909',
            'customerEmail' => 'test@example.com',
            'dueDate' => (new \DateTime('+7 days'))->format('Y-m-d'),
            'description' => 'Test Boleto Payment',
        ];

        $data = array_merge($defaults, $overrides);

        return BoletoPaymentRequest::create(
            amount: $data['amount'],
            customerName: $data['customerName'],
            customerDocument: $data['customerDocument'],
            customerEmail: $data['customerEmail'],
            dueDate: $data['dueDate'],
            description: $data['description'] ?? null
        );
    }

    /**
     * Cria um SubscriptionRequest válido para testes
     */
    public static function createSubscriptionRequest(array $overrides = []): SubscriptionRequest
    {
        $defaults = [
            'amount' => 99.90,
            'interval' => 'monthly',
            'customerEmail' => 'test@example.com',
            'cardToken' => 'tok_test_123',
            'description' => 'Test Subscription',
        ];

        $data = array_merge($defaults, $overrides);

        return SubscriptionRequest::create(
            amount: $data['amount'],
            interval: $data['interval'],
            customerEmail: $data['customerEmail'],
            cardToken: $data['cardToken'],
            description: $data['description'] ?? null
        );
    }

    /**
     * Cria um RefundRequest válido para testes
     */
    public static function createRefundRequest(string $transactionId, array $overrides = []): RefundRequest
    {
        $defaults = [
            'amount' => 100.00,
            'reason' => 'Test refund',
        ];

        $data = array_merge($defaults, $overrides);

        return RefundRequest::create(
            transactionId: $transactionId,
            amount: $data['amount'],
            reason: $data['reason'] ?? null
        );
    }

    /**
     * Cria um CustomerRequest válido para testes
     */
    public static function createCustomerRequest(array $overrides = []): CustomerRequest
    {
        $defaults = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'taxId' => '12345678909',
            'phone' => '11999999999',
        ];

        $data = array_merge($defaults, $overrides);

        return CustomerRequest::create(
            name: $data['name'],
            email: $data['email'],
            taxId: $data['taxId'],
            phone: $data['phone'] ?? null
        );
    }

    /**
     * Cria um PaymentLinkRequest válido para testes
     */
    public static function createPaymentLinkRequest(array $overrides = []): PaymentLinkRequest
    {
        $defaults = [
            'amount' => 100.00,
            'description' => 'Test Payment Link',
        ];

        $data = array_merge($defaults, $overrides);

        return PaymentLinkRequest::create(
            amount: $data['amount'],
            description: $data['description'] ?? null
        );
    }

    /**
     * Retorna dados de cartão válidos para testes
     */
    public static function getValidCardData(): array
    {
        return [
            'number' => '4111111111111111',
            'holder_name' => 'TEST USER',
            'expiry_month' => '12',
            'expiry_year' => '2028',
            'cvv' => '123'
        ];
    }

    /**
     * Retorna números de cartão de teste para diferentes bandeiras
     */
    public static function getTestCardNumbers(): array
    {
        return [
            'visa' => '4111111111111111',
            'mastercard' => '5555555555554444',
            'amex' => '378282246310005',
            'diners' => '30569309025904',
            'discover' => '6011111111111117',
            'elo' => '6362970000457013',
        ];
    }

    /**
     * Retorna CPFs válidos para testes
     */
    public static function getValidCPFs(): array
    {
        return [
            '12345678909',
            '11144477735',
            '52998224725',
        ];
    }

    /**
     * Retorna CNPJs válidos para testes
     */
    public static function getValidCNPJs(): array
    {
        return [
            '11222333000181',
            '12345678000195',
        ];
    }
}
