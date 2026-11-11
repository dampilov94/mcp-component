<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;

/** Built-in guide shipped in the component's docs/ directory. */
final class HelpAction extends Action {
    public function name() { return 'help'; }

    public function description() {
        return 'Built-in guide. Without `topic`, returns the index and the list of topics.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'topic' => Schema::str('Topic name from the topic list.', array('pattern' => '^[a-z0-9_]{1,64}$')),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'topics'  => Schema::arr(Schema::str()),
            'topic'   => Schema::str(),
            'content' => Schema::str(),
        ), array('topics', 'topic', 'content'), true);
    }

    public function run(array $args, Context $context) {
        $dir = $context->corePath . 'docs/';
        $topics = array();
        foreach ((array) glob($dir . '*.md') as $file) { $topics[] = basename($file, '.md'); }
        sort($topics);
        $topic = isset($args['topic']) ? $args['topic'] : 'index';
        if (!in_array($topic, $topics, true)) {
            throw new ApiException('not_found', "Unknown help topic '{$topic}'.", array('topics' => $topics));
        }
        $content = @file_get_contents($dir . $topic . '.md');
        if ($content === false) { throw new \RuntimeException('Cannot read help topic.'); }
        return array('topics' => $topics, 'topic' => $topic, 'content' => $content);
    }
}
