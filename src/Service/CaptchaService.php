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
            // Login always requires captcha for security
            return true;
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
     * Generates a clean math captcha image as a base64 Data URL.
     */
    public function generateImage(Request $request, string $context): string
    {
        $question = $this->generateQuestion($request, $context);
        return $this->generateImageFromQuestion($question);
    }

    public function generateImageFromQuestion(string $question): string
    {
        $text = $question . " = ?";

        $width = 160;
        $height = 45;
        $img = imagecreatetruecolor($width, $height);

        // White background, dark color letters/numbers, dark border
        $bgColor = imagecolorallocate($img, 255, 255, 255); // Pure white #ffffff
        $textColor = imagecolorallocate($img, 15, 23, 42); // Dark slate #0f172a
        $noiseColor = imagecolorallocate($img, 226, 232, 240); // Light noise #e2e8f0
        $borderColor = imagecolorallocate($img, 15, 23, 42); // Dark border #0f172a

        imagefill($img, 0, 0, $bgColor);

        // Add subtle background grid noise lines for anti-scraping
        for ($i = 0; $i < 4; $i++) {
            imageline($img, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $noiseColor);
        }

        // Draw crisp dark border around image
        imagerectangle($img, 0, 0, $width - 1, $height - 1, $borderColor);
        imagerectangle($img, 1, 1, $width - 2, $height - 2, $borderColor);

        // Write text (using built-in font size 5)
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        $x = (int)(($width - $textWidth) / 2);
        $y = (int)(($height - $textHeight) / 2);

        imagestring($img, $font, $x, $y, $text, $textColor);

        ob_start();
        imagepng($img);
        $pngData = ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($pngData);
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
