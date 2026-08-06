<?php
/**
 * QA Test Data Generator for CheckIn Application
 * Senior Quality Assurance & Payment Infrastructure Engineering
 */

declare(strict_types=1);

$targetDir = __DIR__ . '/../var/qa_fixtures';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

echo "=======================================================\n";
echo " GENERATING EXHAUSTIVE QA TEST DATASETS & FIXTURES\n";
echo "=======================================================\n\n";

// 1. Valid CSV Dataset
$validCsv = "first_name,last_name,email,phone,pin\n"
    . "Alice,Smith,alice.smith@example.com,+15550101001,1001\n"
    . "Bob,Jones,bob.jones@example.com,+15550101002,1002\n"
    . "Charlie,Brown,charlie.b@example.com,+15550101003,1003\n";
file_put_contents($targetDir . '/csv_valid.csv', $validCsv);
echo "[+] Created: var/qa_fixtures/csv_valid.csv (Valid 3-row import payload)\n";

// 2. Malformed / Special Character CSV (UTF-8, Unicode, Quotes, O'Connor)
$malformedCsv = "first_name,last_name,email,phone,pin\n"
    . "Renée,O'Connor,renee.o@example.com,+1(555) 010-9999,9901\n"
    . "José-María,Nuñez,jose.n@example.com,555.010.8888,9902\n"
    . "Müller,François,muller.f@example.com,+49 30 123456,9903\n"
    . "\"Quoted, Name\",With Comma,quoted@example.com,1234567890,9904\n";
file_put_contents($targetDir . '/csv_special_chars.csv', $malformedCsv);
echo "[+] Created: var/qa_fixtures/csv_special_chars.csv (Special chars, UTF-8, quoted strings)\n";

// 3. Security Boundary / Injection Payload CSV
$securityCsv = "first_name,last_name,email,phone,pin\n"
    . "<script>alert('xss')</script>,Hacker,xss@example.com,5550001,0001\n"
    . "' OR 1=1 --,SQLi,sqli@example.com,5550002,0002\n"
    . "../../../etc/passwd,LFI,lfi@example.com,5550003,0003\n"
    . "Null\0Byte,Exploit,null@example.com,5550004,0004\n";
file_put_contents($targetDir . '/csv_security_injection.csv', $securityCsv);
echo "[+] Created: var/qa_fixtures/csv_security_injection.csv (XSS, SQLi, LFI, Null Byte vectors)\n";

// 4. Large Boundary CSV (10,000 records performance benchmark)
$handle = fopen($targetDir . '/csv_boundary_10k.csv', 'w');
fputcsv($handle, ['first_name', 'last_name', 'email', 'phone', 'pin']);
for ($i = 1; $i <= 10000; $i++) {
    fputcsv($handle, [
        'User' . $i,
        'Benchmark',
        "bench.user{$i}@qa-stress-test.com",
        '+1555' . str_pad((string)$i, 7, '0', STR_PAD_LEFT),
        str_pad((string)($i % 9999 + 1), 4, '0', STR_PAD_LEFT)
    ], ',', '"', '\\');
}
fclose($handle);
echo "[+] Created: var/qa_fixtures/csv_boundary_10k.csv (10,000 row high-volume stress test dataset)\n";

// 5. Mock Stripe Webhook Payloads (Checkout Completed, Payment Succeeded, Payment Failed, Subscription Deleted)
$stripeCheckoutCompleted = json_encode([
    'type' => 'checkout.session.completed',
    'data' => [
        'object' => [
            'id' => 'cs_test_a1b2c3d4e5',
            'customer' => 'cus_qa_test_customer_01',
            'subscription' => 'sub_qa_test_sub_01',
            'amount_total' => 2900,
            'metadata' => [
                'plan' => 'pro'
            ]
        ]
    ]
], JSON_PRETTY_PRINT);
file_put_contents($targetDir . '/stripe_webhook_checkout_completed.json', $stripeCheckoutCompleted);
echo "[+] Created: var/qa_fixtures/stripe_webhook_checkout_completed.json\n";

$stripePaymentFailed = json_encode([
    'type' => 'invoice.payment_failed',
    'data' => [
        'object' => [
            'id' => 'in_qa_failed_01',
            'customer' => 'cus_qa_test_customer_01',
            'amount_due' => 2900,
            'attempt_count' => 1
        ]
    ]
], JSON_PRETTY_PRINT);
file_put_contents($targetDir . '/stripe_webhook_payment_failed.json', $stripePaymentFailed);
echo "[+] Created: var/qa_fixtures/stripe_webhook_payment_failed.json\n";

echo "\n[✓] All QA Test Data Generation Complete. Fixtures saved in var/qa_fixtures/\n";
