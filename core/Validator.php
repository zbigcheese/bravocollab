<?php

class Validator
{
    private array $errors = [];

    public function required(string $value, string $field): self
    {
        if (trim($value) === '') {
            $this->errors[$field] = "{$field} is required";
        }
        return $this;
    }

    public function email(string $value, string $field = 'email'): self
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "Invalid email address";
        }
        return $this;
    }

    public function minLength(string $value, int $min, string $field): self
    {
        if (mb_strlen($value) < $min) {
            $this->errors[$field] = "{$field} must be at least {$min} characters";
        }
        return $this;
    }

    public function maxLength(string $value, int $max, string $field): self
    {
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "{$field} must be at most {$max} characters";
        }
        return $this;
    }

    public function match(string $value1, string $value2, string $field): self
    {
        if ($value1 !== $value2) {
            $this->errors[$field] = "Values do not match";
        }
        return $this;
    }

    public function password(string $value, string $field = 'password'): self
    {
        if (mb_strlen($value) < 8) {
            $this->errors[$field] = "Password must be at least 8 characters";
        } elseif (!preg_match('/[A-Z]/', $value)) {
            $this->errors[$field] = "Password must contain at least one uppercase letter";
        } elseif (!preg_match('/[0-9]/', $value)) {
            $this->errors[$field] = "Password must contain at least one number";
        }
        return $this;
    }

    public function hexColor(string $value, string $field = 'color'): self
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            $this->errors[$field] = "Invalid color format";
        }
        return $this;
    }

    public function integer($value, string $field): self
    {
        if (!is_numeric($value) || (int) $value != $value) {
            $this->errors[$field] = "{$field} must be an integer";
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors ? reset($this->errors) : null;
    }

    public static function sanitize(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    public static function sanitizeInt($value): int
    {
        return (int) $value;
    }
}
