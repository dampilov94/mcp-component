<?php
namespace ModxMCP\Prompt;

final class FixErrorsPrompt extends Prompt {
    public function name() { return 'fix_errors'; }
    public function title() { return 'Разобрать ошибки сайта'; }
    public function description() { return 'Разобрать журнал ошибок, найти причины и предложить исправления.'; }
    public function requiredTools() { return array('modx_read_log'); }

    public function arguments() {
        return array(self::argument('since', 'За какой период, например «сегодня»', false));
    }

    protected function text(array $args) {
        return "Разбери ошибки сайта.\n\n"
            . "1. `modx_read_log`: свежие ошибки.\n"
            . "2. Сгруппируй их по сообщению и месту.\n"
            . "3. Для каждой группы найди элемент или файл-причину: `modx_search`, `modx_find_usages`, `modx_view_code`.\n"
            . "4. Объясни причину и предложи исправление.\n"
            . "5. Меняй только после подтверждения человека; каждое исправление отдельным changeset.\n"
            . "6. После исправлений снова прочитай журнал (`modx_read_log`) и сообщи, что осталось.";
    }
}
