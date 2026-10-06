<?php
/** tvinputs operations for the modxMCP facade; shared private helpers remain in the same class scope. */
trait ModxMCPTvinputsTrait {
    /**
     * Available TV input (widget) types. Core types are listed statically; any custom
     * types registered by other components (e.g. MIGX) are collected from OnTVInputRenderList,
     * exactly as the manager builds the TV 'Input Type' dropdown.
     */
    private function listTvInputTypes() {
        // Each core type carries `use` (when to pick it) + `requires` (extra create_element keys)
        // so a model can choose the right field_type without reading the docs. See help topic
        // tv_input_types for full examples.
        $g = function ($label, $use, $requires) { return array('label' => $label, 'use' => $use, 'requires' => $requires); };
        $core = array(
            'text'             => $g('Text', 'short single-line text (titles, labels, css class)', array()),
            'textarea'         => $g('Textarea', 'multi-line plain text (no editor)', array()),
            'textareamini'     => $g('Textarea (Mini)', 'a few lines of plain text', array()),
            'rawtext'          => $g('Text (No Filters)', 'single-line text stored raw (HTML/code, not output-filtered)', array()),
            'rawtextarea'      => $g('Textarea (No Filters)', 'multi-line raw text/HTML/code (not filtered)', array()),
            'richtext'         => $g('RichText', 'formatted body content via the WYSIWYG editor', array()),
            'date'             => $g('Date', 'a date / datetime value', array('input_properties.format? (e.g. %Y-%m-%d)')),
            'number'           => $g('Number', 'a numeric value', array('input_properties.min/max/step? (optional)')),
            'email'            => $g('Email', 'an email address', array()),
            'url'              => $g('URL', 'a URL', array()),
            'hidden'           => $g('Hidden', 'a value not shown in the edit form', array()),
            'checkbox'         => $g('Checkbox', 'one or more on/off options', array('elements (Label==value||...) for multiple', 'default_text?')),
            'listbox'          => $g('Listbox (Single-Select)', 'pick ONE value from a fixed list', array('elements (Label==value||...)', 'default_text?')),
            'listbox-multiple' => $g('Listbox (Multi-Select)', 'pick SEVERAL values from a list', array('elements (Label==value||...)')),
            'radio'            => $g('Radio Options', 'pick ONE value shown as radio buttons', array('elements (Label==value||...)', 'default_text?')),
            'image'            => $g('Image', 'pick/upload an image', array('media_source (id)')),
            'file'             => $g('File', 'pick/upload a file', array('media_source (id)')),
            'resourcelist'     => $g('Resource List', 'pick a MODX resource (page) by id', array('input_properties.parents? to limit the tree')),
            'tag'              => $g('Tag', 'comma-separated tags', array()),
            'autotag'          => $g('Auto-Tag', 'tags with auto-complete', array()),
        );
        $custom = array();
        // OnTVInputRenderList is a manager event; some add-ons (e.g. Ace) implement it assuming a
        // manager-controller context and fatal headlessly. A misbehaving plugin must not 500 this
        // action — catch it and just skip its custom types. (Two catches for PHP 5/7+ portability.)
        $results = null;
        try {
            $results = $this->modx->invokeEvent('OnTVInputRenderList');
        } catch (Exception $e) {
            $results = null;
        } catch (Throwable $e) {
            $results = null;
        }
        if (is_array($results)) {
            foreach ($results as $res) {
                if (is_array($res)) { $res = implode("\n", $res); }
                foreach (preg_split('/\r\n|\r|\n/', (string) $res) as $line) {
                    $line = trim($line);
                    if ($line === '') { continue; }
                    // Some add-ons (e.g. MIGX) return a render-templates directory path instead
                    // of a "key==Label" pair; skip those — they aren't usable type keys.
                    if (strpos($line, '==') !== false) {
                        list($k, $lbl) = explode('==', $line, 2);
                        $custom[trim($k)] = trim($lbl);
                    } elseif (strpos($line, '/') === false && strpos($line, '\\') === false) {
                        $custom[$line] = $line;
                    }
                }
            }
        }
        // Some add-ons register their input type via the OnTVInputRenderList event, which may
        // have been swallowed above if another plugin on it fataled. Add well-known ones
        // explicitly when their namespace is installed, so they're still reported.
        if ($this->modx->getObject('modNamespace', array('name' => 'migx'))) {
            if (!isset($custom['migx']))   { $custom['migx'] = 'MIGX'; }
            if (!isset($custom['migxdb'])) { $custom['migxdb'] = 'MIGXdb'; }
        }
        if (!isset($custom['colorpicker']) && $this->modx->getObject('modNamespace', array('name' => 'colorpicker'))) {
            $custom['colorpicker'] = 'ColorPicker';
        }
        return array('core' => $core, 'custom' => $custom);
    }

    /**
     * Suggest the right TV input type for a described need. Deterministic keyword rules
     * (English + Russian), ranked, with a ready-to-edit create_element skeleton for the top
     * pick. Complements the tv_input_types doc — helps a model that's unsure choose correctly.
     * data: description (required).
     */
    private function suggestTvType($data) {
        $desc = isset($data['description']) ? mb_strtolower((string) $data['description'], 'UTF-8') : '';
        if (trim($desc) === '') { throw new ModxMCPClientException('suggest_tv_type: "description" is required.'); }

        // Ordered most-specific-first so ties favour the more specific type. Keywords bilingual.
        $rules = array(
            array('type' => 'migx',         'why' => 'repeating structured rows (each with several fields)', 'kw' => array('migx', 'repeat', 'rows', 'gallery', 'slides', 'slider', 'carousel', 'list of items', 'blocks', 'multiple items', 'галере', 'слайд', 'слайдер', 'карусел', 'повторя', 'список блок', 'несколько элемент', 'список товар', 'список услуг')),
            array('type' => 'image',        'why' => 'a single image', 'kw' => array('image', 'photo', 'picture', 'logo', 'banner', 'icon', 'thumbnail', 'изображ', 'картинк', 'фото', 'логотип', 'баннер', 'иконк', 'превью')),
            array('type' => 'file',         'why' => 'a downloadable file', 'kw' => array('file', 'document', 'pdf', 'download', 'attachment', 'файл', 'документ', 'скачать', 'вложен')),
            array('type' => 'colorpicker',  'why' => 'a color value', 'kw' => array('color', 'colour', 'цвет', 'окрас')),
            array('type' => 'date',         'why' => 'a date / time value', 'kw' => array('date', 'time', 'datetime', 'deadline', 'schedule', 'дата', 'время', 'срок', 'расписан')),
            array('type' => 'number',       'why' => 'a numeric value', 'kw' => array('number', 'numeric', 'count', 'quantity', 'qty', 'amount', 'price', 'integer', 'weight', 'rating', 'число', 'количеств', 'цена', 'сумма', 'вес', 'рейтинг')),
            array('type' => 'email',        'why' => 'an email address', 'kw' => array('email', 'e-mail', 'почт', 'эл. адрес')),
            array('type' => 'resourcelist', 'why' => 'a link to another MODX resource (page)', 'kw' => array('related page', 'link to a page', 'resource', 'parent page', 'choose a page', 'связанн', 'ссылка на страниц', 'ресурс', 'выбрать страниц', 'родительск')),
            array('type' => 'url',          'why' => 'a URL / web link', 'kw' => array('url', 'website', 'web link', 'external link', 'href', 'ссылк', 'сайт', 'веб-адрес')),
            array('type' => 'richtext',     'why' => 'formatted content via the WYSIWYG editor', 'kw' => array('rich', 'wysiwyg', 'formatted', 'html content', 'article body', 'content body', 'editor', 'форматир', 'визуальн', 'редактор', 'статья', 'контент тела', 'вёрстк')),
            array('type' => 'listbox-multiple', 'why' => 'pick SEVERAL values from a list', 'kw' => array('multiple', 'several', 'multi-select', 'multiselect', 'many of', 'несколько из', 'мультивыбор', 'много значен')),
            array('type' => 'listbox',      'why' => 'pick ONE value from a fixed list', 'kw' => array('select', 'dropdown', 'choose one', 'option', 'status', 'choice', 'list of values', 'выбор', 'выпадающ', 'один из', 'опци', 'статус', 'вариант')),
            array('type' => 'radio',        'why' => 'pick ONE value as radio buttons', 'kw' => array('radio', 'радио', 'переключател')),
            array('type' => 'checkbox',     'why' => 'on/off toggle(s)', 'kw' => array('checkbox', 'yes/no', 'yes no', 'boolean', 'toggle', 'flag', 'on/off', 'enable', 'чекбокс', 'да/нет', 'да нет', 'флаг', 'вкл', 'переключ', 'булев')),
            array('type' => 'tag',          'why' => 'comma-separated tags', 'kw' => array('tag', 'tags', 'keywords', 'тег', 'метк', 'ключев слов')),
            array('type' => 'textarea',     'why' => 'multi-line plain text', 'kw' => array('textarea', 'multi-line', 'multiline', 'paragraph', 'description', 'note', 'многострочн', 'абзац', 'описани', 'заметк')),
            array('type' => 'rawtextarea',  'why' => 'raw HTML/code, not output-filtered', 'kw' => array('raw html', 'embed code', 'script', 'iframe', 'код', 'встроить код', 'сырой html')),
            array('type' => 'text',         'why' => 'short single-line text', 'kw' => array('title', 'name', 'label', 'heading', 'short text', 'css class', 'заголов', 'назван', 'короткий текст', 'css класс', 'метка')),
        );

        $scored = array();
        foreach ($rules as $i => $r) {
            $score = 0; $hits = array();
            foreach ($r['kw'] as $kw) {
                if (mb_strpos($desc, mb_strtolower($kw, 'UTF-8'), 0, 'UTF-8') !== false) { $score++; $hits[] = $kw; }
            }
            if ($score > 0) { $scored[] = array('type' => $r['type'], 'why' => $r['why'], 'score' => $score, 'matched' => $hits, 'order' => $i); }
        }
        // Sort by score desc, then by specificity (earlier rule wins ties).
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) { return $b['score'] - $a['score']; }
            return $a['order'] - $b['order'];
        });

        // colorpicker only if the add-on is installed; else fall back to text with a note.
        $types = $this->listTvInputTypes();
        $colorAvailable = isset($types['custom']['colorpicker']);
        $note = null;
        foreach ($scored as $k => $c) {
            if ($c['type'] === 'colorpicker' && !$colorAvailable) {
                $scored[$k]['type'] = 'text';
                $scored[$k]['why'] = 'a color value (no colorpicker add-on installed — use text, or install one)';
                $note = 'No colorpicker input type is installed; suggesting text. Install a colorpicker add-on for a real picker.';
            }
        }

        if (!$scored) {
            $scored[] = array('type' => 'text', 'why' => 'no strong signal — short single-line text is the safe default', 'score' => 0, 'matched' => array());
        }

        // Skeleton for the top pick.
        $top = $scored[0]['type'];
        $reqMap = array(
            'image' => array('media_source' => '<media source id — see modx_list_media_sources>'),
            'file'  => array('media_source' => '<media source id — see modx_list_media_sources>'),
            'listbox' => array('elements' => 'Label==value||Label2==value2'),
            'listbox-multiple' => array('elements' => 'Label==value||Label2==value2'),
            'radio' => array('elements' => 'Label==value||Label2==value2'),
            'checkbox' => array('elements' => 'Yes==1'),
            'resourcelist' => array('input_properties' => array('parents' => '<optional parent id to limit the picker>')),
            'colorpicker' => array('input_properties' => array('format' => 'hex')),
            'migx' => array('input_properties' => array('configs' => '', 'formtabs' => '<see the migx help topic>', 'columns' => '<see the migx help topic>')),
        );
        $skel = array('name' => '<tv_name>', 'caption' => '<Caption>', 'field_type' => $top, 'templates' => array('<template id>'));
        if (isset($reqMap[$top])) { $skel = array_merge($skel, $reqMap[$top]); }

        return array(
            'description' => (string) $data['description'],
            'candidates'  => array_slice($scored, 0, 3),
            'recommended' => $top,
            'create_skeleton' => array('action' => 'create_element', 'type' => 'tv', 'data' => $skel),
            'note' => $note,
            'docs' => 'See the tv_input_types help topic (and migx for repeating rows).',
        );
    }
}
