<?php

namespace App\Filament\Resources\LlmModels\Schemas;

use App\Models\LlmModel;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class LlmModelForm
{
    public static function configure(Schema $schema): Schema
    {
        $price = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->prefix('$')
            ->suffix('per 1M tokens');

        return $schema
            ->components([
                TextInput::make('provider')
                    ->required()
                    ->default('openai')
                    ->maxLength(64)
                    // Sent to no API, but matched exactly against recorded usage.
                    ->notRegex('/\s/')
                    ->helperText('Only openai has a digest driver today. Rows for other providers are kept for price comparisons.'),
                TextInput::make('model')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. gpt-5.6-luna')
                    ->helperText('The exact model id the provider\'s API takes.')
                    // Sent verbatim: one pasted space and every digest is a 4xx.
                    ->notRegex('/\s/')
                    ->validationMessages(['not_regex' => 'A model id has no spaces.'])
                    ->rule(fn (Get $get, ?LlmModel $record) => Rule::unique('llm_models', 'model')
                        ->where('provider', $get('provider'))
                        ->ignore($record?->getKey())),
                $price('price_in_per_mtok', 'Input price'),
                $price('price_cached_in_per_mtok', 'Cached input price')
                    ->helperText('Leave empty to charge cached input at the input price.'),
                $price('price_out_per_mtok', 'Output price')
                    ->helperText('Output includes reasoning tokens.'),
                Toggle::make('is_digest_model')
                    ->label('Use for digests')
                    ->helperText('Session digests and backfills run on this model. Marking it unmarks any other. With none marked, the server\'s configured model is used.')
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                        if ($value && ! in_array($get('provider'), LlmModel::DIGEST_PROVIDERS, true)) {
                            $fail('Only an openai model can be used for digests.');
                        }
                    }),
                Textarea::make('notes')
                    ->rows(2)
                    ->helperText('Where the prices came from, and when.'),
            ]);
    }
}
