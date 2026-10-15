<?php

declare(strict_types=1);

namespace Farzai\DatasetKit\Gate;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** Validates decoded JSON against one $defs entry of a dataset's schema.json. */
final class SchemaValidator
{
    private Validator $validator;

    /**
     * @param string $schema the schema document; its $id may carry a {snapshot}
     *                       placeholder the writer fills in
     * @param string $id the concrete URI to register it under, with 0000-00-00
     *                   where the snapshot id goes
     */
    public function __construct(string $schema, private readonly string $id)
    {
        $this->validator = new Validator();
        $this->validator->setMaxErrors(5);
        // Any concrete id makes the placeholder a URI the validator can resolve.
        $this->validator->resolver()->registerRaw(str_replace('{snapshot}', '0000-00-00', $schema), $id);
    }

    /** @return list<string> errors, empty when valid */
    public function errors(mixed $value, string $def): array
    {
        $data = json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
        $result = $this->validator->validate($data, $this->id . "#/\$defs/{$def}");
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$path}: {$message}";
            }
        }

        return $errors;
    }
}
