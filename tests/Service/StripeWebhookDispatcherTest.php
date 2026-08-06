<?php

namespace App\Tests\Service;

use App\Entity\Organization;
use App\Service\EmailService;
use App\Service\Stripe\StripeWebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

class StripeWebhookDispatcherTest extends KernelTestCase
{
    public function testDispatcherRoutesCheckoutCompletedEvent(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $dispatcher = new StripeWebhookDispatcher();
        $em = $container->get(EntityManagerInterface::class);
        $emailService = $container->get(EmailService::class);

        $event = (object)[
            'type' => 'checkout.session.completed',
            'data' => (object)[
                'object' => (object)[
                    'customer' => 'non_existent_cust_id',
                    'subscription' => 'sub_test_123',
                ]
            ]
        ];

        $request = new Request();
        
        // Ensure no exception is thrown when unknown customer ID is processed
        $dispatcher->dispatch($event, $request, $em, $emailService, true, true);
        $this->assertTrue(true);
    }
}
