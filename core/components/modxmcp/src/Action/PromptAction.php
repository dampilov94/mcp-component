<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Prompt\Prompt;
use ModxMCP\Schema;

/** Protocol action: the messages of one scenario. Not a tool itself. */
final class PromptAction extends Action {
    public function name() { return 'prompt'; }

    public function description() { return 'Messages of a ready-made scenario.'; }

    public function exposed() { return false; }

    public function inputSchema() {
        return Schema::obj(array(
            'name'      => Schema::str('Prompt name from the manifest.', array('pattern' => '^[a-z][a-z0-9_]{0,63}$')),
            'arguments' => Schema::obj(array(), array(), true),
        ), array('name'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'description' => Schema::str(),
            'messages'    => Schema::arr(Schema::obj(array(), array(), true)),
        ), array('description', 'messages'));
    }

    public function run(array $args, Context $context) {
        $registry = $context->prompts;
        $prompt = $registry->get($args['name']);
        if ($prompt === null) { throw new ApiException('not_found', "Unknown prompt '{$args['name']}'."); }
        $blocked = $registry->blockReason($prompt, $context);
        if ($blocked !== null) {
            $details = array('reason' => $blocked[0]);
            if ($blocked[1] !== null) { $details['tool'] = $blocked[1]; }
            throw new ApiException('unavailable', "Prompt '{$prompt->name()}' is unavailable: {$blocked[0]}" . ($blocked[1] !== null ? " ({$blocked[1]})" : '') . '.', $details);
        }
        $given = isset($args['arguments']) ? $args['arguments'] : array();
        if (!is_array($given)) { throw new ApiException('validation_failed', 'arguments must be an object.'); }
        $known = array();
        foreach ($prompt->arguments() as $argument) { $known[$argument['name']] = $argument; }
        foreach ($given as $name => $value) {
            $name = (string) $name;
            if (!isset($known[$name])) { throw new ApiException('validation_failed', "Unknown argument '{$name}'. Known: " . ($known ? implode(', ', array_keys($known)) : 'none') . '.'); }
            if (!is_string($value)) { throw new ApiException('validation_failed', "arguments.{$name} must be a string."); }
            if (self::length($value) > Prompt::MAX_ARGUMENT) { throw new ApiException('validation_failed', "arguments.{$name} is too long (at most " . Prompt::MAX_ARGUMENT . ' characters).'); }
        }
        foreach ($known as $name => $argument) {
            if ($argument['required'] && (!isset($given[$name]) || trim($given[$name]) === '')) {
                throw new ApiException('validation_failed', "Argument '{$name}' is required.");
            }
        }
        return array('description' => $prompt->description(), 'messages' => $prompt->messages($given, $context));
    }

    private static function length($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
