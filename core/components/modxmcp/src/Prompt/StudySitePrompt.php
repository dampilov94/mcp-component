<?php
namespace ModxMCP\Prompt;

final class StudySitePrompt extends Prompt {
    public function name() { return 'study_site'; }
    public function title() { return 'Изучить сайт'; }
    public function description() { return 'Составить краткую карту сайта, ничего не меняя.'; }

    protected function text(array $args) {
        return "Изучи сайт перед работой. Ничего не меняй.\n\n"
            . "1. `modx_site_info`: версии, счётчики, контексты, установленные дополнения.\n"
            . "2. `modx_capabilities`: что доступно этому токену и почему что-то недоступно.\n"
            . "3. `modx_help {topic: \"getting_started\"}`: рабочее руководство.\n"
            . "4. `modx_resource_tree`: верхние уровни дерева страниц.\n"
            . "5. Шаблоны и их TV: `modx_list {kind: \"template\"}` и `modx_list {kind: \"tv\"}`.\n"
            . "6. `modx_dependency_graph {format: \"summary\"}`: как связаны элементы.\n"
            . "7. `modx_audit_site`: только сводка.\n\n"
            . "Ответь короткой картой сайта: контексты, типы страниц, ключевые чанки и сниппеты, дополнения, найденные проблемы.";
    }
}
