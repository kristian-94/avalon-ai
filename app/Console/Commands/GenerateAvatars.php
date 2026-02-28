<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GenerateAvatars extends Command
{
    protected $signature = 'avatars:generate {--only= : Generate only a specific character name}';

    protected $description = 'Generate avatar images for AI players using OpenAI DALL-E';

    private const STYLE_PREFIX = 'Circular portrait avatar, dark fantasy art style, painterly medieval illustration, moody lighting, dark background with subtle gold rim border. Character bust portrait, no text, no words, no letters.';

    private const PROMPTS = [
        'Max' => 'A noble knight with earnest, wide eyes, wearing polished plate armor and a red tabard. Strong jaw, clean-shaven, short brown hair. Expression is dead serious and reverent, like someone who truly believes they are a knight of the round table. Warm golden light on face.',
        'Alex' => 'A young person with a raised eyebrow and a subtle smirk, wearing a dark hooded cloak pulled back casually. Messy dark hair, sharp features, arms crossed. Expression is unimpressed and amused. Cool blue-gray tones.',
        'Sam' => 'A bespectacled scholar with ink-stained fingers, wearing a leather vest over a linen shirt. Curly auburn hair, freckles, leaning forward eagerly. Expression is excited and analytical, like they just figured something out. Warm candlelight tones.',
        'Jordan' => 'A relaxed, easygoing person leaning back slightly, wearing a loose-fitting tunic and a beaded necklace. Wavy shoulder-length hair, warm skin, half-smile. Expression is calm and perceptive. Warm sunset tones, soft lighting.',
        'Riley' => 'A fierce warrior with a scar across one cheek, wearing leather battle armor with metal studs. Short-cropped hair, intense piercing eyes, clenched jaw. Expression is confrontational and determined. Harsh red-tinged lighting.',
        'Taylor' => 'A graceful mediator wearing elegant but practical robes in deep purple, with a silver brooch. Gentle features, warm brown eyes, neatly braided hair. Expression is kind and reassuring, with a slight calming smile. Soft warm lighting.',
        'Morgan' => 'A mysterious, shadowy figure with half their face in darkness, wearing a dark high-collared coat. Angular features, pale eyes that catch the light, thin lips. Expression is unreadable and observant. Cool dark tones with a single shaft of light.',
        'Jamie' => 'An animated, bright-eyed young person mid-gesture, wearing a colorful patchwork vest over chainmail. Wild curly hair going in every direction, wide grin, flushed cheeks. Expression is pure excitement. Warm, vibrant lighting.',
    ];

    public function handle(): int
    {
        $apiKey = env('OPEN_AI_API_KEY');

        if (! $apiKey) {
            $this->error('OPEN_AI_API_KEY not set');
            return 1;
        }

        $only = $this->option('only');
        $prompts = $only ? [$only => self::PROMPTS[$only] ?? null] : self::PROMPTS;

        if ($only && ! isset(self::PROMPTS[$only])) {
            $this->error("Unknown character: {$only}");
            return 1;
        }

        foreach ($prompts as $name => $prompt) {
            $this->info("Generating avatar for {$name}...");

            $fullPrompt = self::STYLE_PREFIX . ' ' . $prompt;

            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => 'dall-e-3',
                    'prompt' => $fullPrompt,
                    'n' => 1,
                    'size' => '1024x1024',
                    'quality' => 'standard',
                ]);

            if (! $response->successful()) {
                $this->error("Failed for {$name}: " . $response->body());
                continue;
            }

            $url = $response->json('data.0.url');

            if (! $url) {
                $this->error("No URL in response for {$name}");
                continue;
            }

            // Download the image
            $imageData = Http::get($url)->body();
            $path = public_path("avatars/" . strtolower($name) . ".png");
            file_put_contents($path, $imageData);

            $this->info("Saved: {$path}");
        }

        $this->info('Done!');
        return 0;
    }
}
