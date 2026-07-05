<?php

namespace App\Service;

class TokenGeneratorService
{
    private const ADJECTIVES = [
        'happy', 'bright', 'swift', 'calm', 'clever', 'brave', 'gentle', 
        'proud', 'eager', 'jolly', 'kind', 'lively', 'nice', 'polite', 
        'quiet', 'silly', 'smart', 'sweet', 'warm', 'wise', 'zesty',
        'cool', 'neat', 'fresh', 'crisp', 'smooth', 'bold', 'grand'
    ];

    private const COLORS = [
        'blue', 'green', 'red', 'yellow', 'purple', 'orange', 'pink', 
        'brown', 'white', 'teal', 'cyan', 'indigo', 'violet', 'coral',
        'peach', 'mint', 'plum', 'ruby', 'jade', 'amber', 'navy'
    ];

    private const NOUNS = [
        'panda', 'koala', 'tiger', 'lion', 'bear', 'wolf', 'fox', 'deer',
        'eagle', 'hawk', 'owl', 'swan', 'dove', 'robin', 'finch', 'crane',
        'whale', 'shark', 'seal', 'otter', 'frog', 'toad', 'newt', 'gecko',
        'apple', 'berry', 'grape', 'lemon', 'mango', 'melon', 'peach', 'pear',
        'river', 'ocean', 'forest', 'mountain', 'valley', 'meadow', 'island'
    ];

    public function generateHumanReadableToken(): string
    {
        $adj = self::ADJECTIVES[array_rand(self::ADJECTIVES)];
        $color = self::COLORS[array_rand(self::COLORS)];
        $noun = self::NOUNS[array_rand(self::NOUNS)];
        $num = random_int(10, 999);

        $token = sprintf('%s-%s-%s-%d', $adj, $color, $noun, $num);
        
        $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
        return $slugger->slug($token)->lower()->toString();
    }
}
