<?php
namespace ModxMCP\Prompt;

use ModxMCP\Auth\Scope;

final class NewPageTypePrompt extends Prompt {
    public function name() { return 'new_page_type'; }
    public function title() { return 'Новый тип страницы'; }
    public function description() { return 'Спроектировать и создать шаблон, TV и чанки для нового типа страниц.'; }
    public function requiredScope() { return Scope::DEVELOP; }

    public function arguments() {
        return array(self::argument('description', 'Какой тип страницы нужен', true));
    }

    protected function text(array $args) {
        return "Создай новый тип страницы.\n\n"
            . "1. Сначала план: шаблон, TV с типами полей (`modx_invoke {kind: \"tv\", op: \"suggest_type\"}` и `list_input_types`), чанки. Сверься с `modx_help {topic: \"tv_input_types\"}` и с уже существующими шаблонами.\n"
            . "2. Покажи список того, что будет создано, и спроси согласия. Без него ничего не создавай.\n"
            . "3. `modx_checkpoint`.\n"
            . "4. Создавай через `modx_create`; где доступно, сначала с `dry_run`. Прикрепи TV к шаблону.\n"
            . "5. Создай тестовую страницу с `published: false`, проверь её `modx_check_page {id}` (статус, нераспарсенные теги, ошибки в логе), исправь найденное и затем попроси человека открыть её предпросмотр из менеджера MODX.\n"
            . "6. Сообщи, что создано, и как всё отменить: `modx_undo {to_checkpoint}`.";
    }
}
