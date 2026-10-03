<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Services\Aeon\Data\AeonAccess;
use App\Services\Aeon\Operations\FormSpecBuilder;
use App\Services\Aeon\Operations\OperationResolver;
use App\Services\Aeon\Operations\RulesIntrospector;

/**
 * Generative UI tool that turns a user's write intent ("create an NCR", "apply for leave")
 * into a live interactive Radix UI form pre-filled with extracted entities.
 */
class PrepareOperationTool implements AeonToolContract
{
    public function __construct(
        private OperationResolver $resolver,
        private RulesIntrospector $introspector,
        private FormSpecBuilder $builder,
        private AeonAccess $access
    ) {}

    public function name(): string
    {
        return 'prepare_operation';
    }

    public function description(): string
    {
        return 'Build an interactive form block for write actions (create, update, apply, report, log). Extracted field values are prefilled.';
    }

    public function parameters(): array
    {
        return [
            'entity' => [
                'type' => 'string',
                'description' => 'Target entity or action name (e.g. "ncr", "leave", "petty cash", "incident", "daily work")',
            ],
            'operation' => [
                'type' => 'string',
                'description' => 'Action kind: "create", "update", "delete", or "action"',
                'enum' => ['create', 'update', 'delete', 'action'],
            ],
            'values' => [
                'type' => 'object',
                'description' => 'Key-value map of field values mentioned by the user (e.g. {"reason": "medical checkup", "start_date": "2026-09-01", "amount": 5000})',
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $operation = (string) ($args['operation'] ?? 'create');
        $values = (array) ($args['values'] ?? []);

        $resolution = $this->resolver->resolve($entity, $operation, $this->access->actor($userId));
        $op = $resolution['best'];

        if (! $op) {
            return [
                'text' => "Could not locate a write route for '{$entity}'.",
                'blocks' => [],
                'data' => ['error' => 'operation_not_found', 'entity' => $entity],
                'terminal' => false,
            ];
        }

        $rules = $this->introspector->forAction(
            (string) $op['controller'],
            (string) $op['action'],
            $op['table'] ?? null
        );

        $formBlock = $this->builder->build($rules, $op, $values, $this->access->actor($userId));

        return [
            'text' => "I've prepared the {$op['label']} form for you. Please review the prefilled values below and submit.",
            'blocks' => [$formBlock],
            'data' => [
                'operation' => $op['name'],
                'uri' => $formBlock['action'],
                'fields_count' => count($formBlock['fields'] ?? []),
            ],
            'terminal' => true, // Stops the loop because the interactive form is presented to the user
        ];
    }
}
