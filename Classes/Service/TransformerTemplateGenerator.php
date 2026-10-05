<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use InvalidArgumentException;
use ParseError;

final readonly class TransformerTemplateGenerator
{
    /**
     * @param array<array-key, string> $arguments
     * @return array{source: string, hasTodos: bool}
     */
    public function generate(array $arguments): array
    {
        if ($arguments === []) {
            throw new InvalidArgumentException('There are no missing argument transformers.', 1791193000);
        }

        $source = "<?php\n\ndeclare(strict_types=1);\n\nuse Andersundsehr\\FrontendStudio\\Transformer\\ArgumentTransformers;\n\nreturn new ArgumentTransformers(...[\n";
        $hasTodos = false;
        foreach ($arguments as $name => $type) {
            if (!is_string($name) || $name === '' || ctype_digit($name)) {
                throw new InvalidArgumentException('Transformer arguments must have nonnumeric names.', 1791193001);
            }

            $returnType = $this->phpType($type);
            $source .= '    ' . var_export($name, true) . ' => ';
            if (ltrim($type, '\\') === 'stdClass') {
                $source .= <<<'PHP'
static function (string $value = '{}'): stdClass {
        $result = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        if (!$result instanceof stdClass) {
            throw new InvalidArgumentException('Expected a JSON object.');
        }
        return $result;
    },
PHP;
            } else {
                $hasTodos = true;
                $message = var_export('TODO: implement transformer for argument "' . $name . '" (' . $type . ').', true);
                $source .= 'static function (string $value = \'\'): ' . $returnType . " {\n"
                    . "        // TODO: replace this exception with your domain-specific transformation.\n"
                    . '        throw new \RuntimeException(' . $message . ");\n    },";
            }

            $source .= "\n";
        }

        $source .= "]);\n";
        try {
            if (token_get_all($source, TOKEN_PARSE) === []) {
                throw new InvalidArgumentException('The transformer template is empty.', 1791193006);
            }
        } catch (ParseError $parseError) {
            throw new InvalidArgumentException('The component type cannot be expressed as a PHP return type. Add its transformer manually.', 1791193002, $parseError);
        }

        return ['source' => $source, 'hasTodos' => $hasTodos];
    }

    private function phpType(string $type): string
    {
        $nullable = str_starts_with($type, '?');
        $parts = explode('|', $nullable ? substr($type, 1) : $type);
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*(?:\[\])?$/D', $part) !== 1) {
                throw new InvalidArgumentException('The component type cannot be expressed as a PHP return type. Add its transformer manually.', 1791193003);
            }

            if (in_array(strtolower(ltrim($part, '\\')), ['void', 'never', 'self', 'parent', 'static', 'callable', 'resource'], true)) {
                throw new InvalidArgumentException('The component type cannot be expressed as a PHP return type. Add its transformer manually.', 1791193004);
            }

            if (in_array(strtolower($part), ['bool', 'boolean', 'int', 'integer', 'float', 'double', 'string', 'array', 'iterable', 'mixed', 'object', 'null', 'true', 'false'], true)) {
                $part = strtolower($part);
            }

            $part = match ($part) {
                'boolean' => 'bool',
                'integer' => 'int',
                'double' => 'float',
                default => $part,
            };
            $result[] = str_ends_with($part, '[]') ? 'array' : (in_array($part, ['bool', 'int', 'float', 'string', 'array', 'iterable', 'mixed', 'object', 'null', 'true', 'false'], true) ? $part : '\\' . ltrim($part, '\\'));
        }

        if (count($parts) > 1 && array_intersect($result, ['mixed', 'object']) !== []) {
            throw new InvalidArgumentException('The component union cannot be expressed as a PHP return type. Add its transformer manually.', 1791193005);
        }

        if (
            ($nullable && array_intersect($result, ['mixed', 'null']) !== [])
            || (in_array('bool', $result, true) && array_intersect($result, ['true', 'false']) !== [])
            || (in_array('true', $result, true) && in_array('false', $result, true))
            || (in_array('iterable', $result, true) && array_intersect($result, ['array', '\\Traversable']) !== [])
        ) {
            throw new InvalidArgumentException('The component union cannot be expressed as a PHP return type. Add its transformer manually.', 1791193007);
        }

        return ($nullable ? '?' : '') . implode('|', array_unique($result));
    }
}
