<?php

namespace App\Rules;

use App\Support\RichText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RichTextMinLength implements ValidationRule
{
    public function __construct(private int $min) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (mb_strlen(RichText::toPlainText((string) $value)) < $this->min) {
            $fail('validation.min.string')->translate(['min' => $this->min]);
        }
    }
}
