<?php
namespace ModxMCP\Prompt;

use ModxMCP\Auth\Scope;

final class ChangePagePrompt extends Prompt {
    public function name() { return 'change_page'; }
    public function title() { return 'Изменить страницу через черновик'; }
    public function description() { return 'Изменить страницу в черновике, показать предпросмотр и применить после согласия человека.'; }
    public function requiredScope() { return Scope::CONTENT; }
    public function requiredTools() { return array('modx_invoke'); }

    public function arguments() {
        return array(
            self::argument('page', 'Страница: id или заголовок', true),
            self::argument('change', 'Что изменить', true),
        );
    }

    protected function text(array $args) {
        return "Измени страницу через черновик: живая страница остаётся прежней, пока человек не согласится.\n\n"
            . "1. Найди страницу (`modx_list {kind: \"resource\", query}` или `modx_get`). Если подходит несколько, спроси, какая нужна.\n"
            . "2. Создай черновик: `modx_invoke {kind: \"resource\", id, op: \"create_draft\"}`.\n"
            . "3. Вноси изменения только в черновик (`draft_id`): код и текст через `modx_edit_code`, поля и TV через `modx_update`.\n"
            . "4. Проверь черновик: `modx_check_page {id: draft_id}` (статус, нераспарсенные теги, ошибки в логе) и исправь найденное. Затем дай человеку ссылку на предпросмотр из менеджера (`preview_url`; её видит только человек, вошедший в менеджер) и краткий план изменений: `op: \"apply_draft\"` без `confirm`.\n"
            . "5. Применяй (`apply_draft` с `confirm: true`) только после слов человека, что можно.\n"
            . "6. Если страница изменилась за это время (`original_changed` или `revision_conflict`), покажи разницу и спроси, что делать.";
    }
}
