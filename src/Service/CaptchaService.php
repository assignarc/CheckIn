<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class CaptchaService
{
    public function __construct(
        private AppConfigService $configService
    ) {}

    /**
     * Checks if the captcha is enabled for a given context.
     * Contexts: 'login', 'demo', 'signup'.
     */
    public function isEnabled(string $context): bool
    {
        if ($context === 'login') {
            return (bool)$this->configService->get('captcha_login_enabled', false);
        }

        if ($context === 'demo') {
            return (bool)$this->configService->get('captcha_demo_enabled', false);
        }

        if ($context === 'signup') {
            // Signup always requires captcha for security
            return true;
        }

        return false;
    }

    /**
     * Generates a random mathematical captcha question and stores the answer in session.
     */
    public function generateQuestion(Request $request, string $context): string
    {
        $num1 = rand(1, 10);
        $num2 = rand(1, 10);
        $op = rand(0, 1) === 0 ? '+' : '-';

        // Ensure no negative results for simplicity
        if ($op === '-' && $num1 < $num2) {
            $temp = $num1;
            $num1 = $num2;
            $num2 = $temp;
        }

        $answer = $op === '+' ? ($num1 + $num2) : ($num1 - $num2);
        
        $session = $request->getSession();
        $session->set('_captcha_result_' . $context, $answer);

        return "{$num1} {$op} {$num2}";
    }

    /**
     * Validates the user's captcha response.
     */
    public function validate(Request $request, string $context, ?string $inputAnswer): bool
    {
        if (!$this->isEnabled($context)) {
            return true;
        }

        if ($inputAnswer === null) {
            return false;
        }

        $session = $request->getSession();
        $correctAnswer = $session->get('_captcha_result_' . $context);

        // Remove the answer to prevent reuse
        $session->remove('_captcha_result_' . $context);

        return (int)$inputAnswer === (int)$correctAnswer;
    }
}
