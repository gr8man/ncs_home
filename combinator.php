<?php

/**
 * Klasa Combinator - generuje wariacje danych z niezależną walidacją,
 * obsługą grup oraz dynamicznym generowaniem pól na podstawie szablonów.
 */
class Combinator {

    /**
     * Konfiguracja metod: reguły (rules), wartości domyślne (defaults)
     * oraz szablony dynamicznych pól (templates).
     */
    private array $config = [
        'drill' => [
            'rules' => [
                'name' => 'required',
                'fn'   => 'required|integer',
                'nf'   => 'required|val[left,right]'
            ],
            'defaults' => [
                'nf' => 'left'
            ],
            'templates' => [
                'name' => 'Drill: {name} ({nf})'
            ]
        ],
        'pin' => [
            'rules' => [
                'id'    => 'required|natural_no_zero',
                'code'  => 'required|numeric|min[1000]|max[9999]',
                'mode'  => 'required'
            ],
            'defaults' => [
                'mode' => 'active'
            ],
            'templates' => [
                'custom_label' => 'Pin {mode} - Grupy: {activeGroups}',
                'description'  => 'Obiekt ID: {id} o kodzie {code}'
            ]
        ]
    ];

    private array $storage = [];
    private array $activeGroups = [];

    /**
     * Definiuje grupy, do których trafią wyniki najbliższego wywołania generacji.
     */
    public function group(array $groups): self {
        $this->activeGroups = $groups;
        return $this;
    }

    /**
     * Czyści bufor dla konkretnej grupy lub wszystkie grupy.
     */
    public function reset(?string $groupName = null): self {
        if ($groupName) {
            unset($this->storage[$groupName]);
        } else {
            $this->storage = [];
        }
        return $this;
    }

    /**
     * Zwraca dane z bufora.
     */
    public function out($filter = null): array {
        if ($filter === null) return $this->storage;
        if (is_string($filter)) return $this->storage[$filter] ?? [];
        if (is_array($filter)) return array_intersect_key($this->storage, array_flip($filter));
        return [];
    }

    /**
     * Uniwersalna metoda do wywoływania akcji na podstawie nazwy z konfiguracji.
     * Pozwala uniknąć definiowania dziesiątek osobnych metod.
     */
    public function action(string $method, array $data): self {
        if (!isset($this->config[$method])) {
            return $this;
        }
        return $this->process($method, $data);
    }

    /**
     * Wspólny procesor dla różnych metod generujących.
     */
    private function process(string $method, array $data): self {
        $conf = $this->config[$method];

        // 1. Aplikowanie wartości domyślnych
        foreach ($conf['defaults'] as $key => $default) {
            if (!isset($data[$key])) {
                $data[$key] = $default;
            }
        }

        // 2. Przygotowanie macierzy i walidacja elementów wejściowych
        $matrix = [];
        foreach ($data as $key => $value) {
            $items = is_array($value) ? $value : [$value];
            $currentRules = isset($conf['rules'][$key]) ? explode('|', $conf['rules'][$key]) : [];
            
            $validItems = [];
            foreach ($items as $item) {
                if ($this->validateField($item, $currentRules)) {
                    $validItems[] = $item;
                }
            }

            // Jeśli brak poprawnych elementów dla pola wymaganego, przerywamy dla tej wariacji
            if (empty($validItems) && in_array('required', $currentRules)) {
                return $this;
            }
            
            $matrix[$key] = $validItems;
        }

        // 3. Generowanie produktu kartezjańskiego
        $variations = $this->generate($matrix);

        // 4. Dynamiczne generowanie pól na podstawie szablonów
        if (isset($conf['templates']) && is_array($conf['templates'])) {
            foreach ($variations as &$variant) {
                foreach ($conf['templates'] as $targetField => $template) {
                    $variant[$targetField] = $this->parseTemplate($template, $variant);
                }
            }
        }

        // 5. Rozdzielenie wyników do grup
        foreach ($this->activeGroups as $groupName) {
            if (!isset($this->storage[$groupName])) $this->storage[$groupName] = [];
            $this->storage[$groupName] = array_merge($this->storage[$groupName], $variations);
        }

        return $this;
    }

    /**
     * Parsuje szablon podmieniając tagi {klucz} na wartości.
     */
    private function parseTemplate(string $template, array $variant): string {
        $placeholders = [
            '{activeGroups}' => implode(', ', $this->activeGroups)
        ];

        foreach ($variant as $key => $value) {
            $placeholders['{' . $key . '}'] = is_array($value) ? implode(',', $value) : $value;
        }

        return strtr($template, $placeholders);
    }

    /**
     * Algorytm generujący produkt kartezjański.
     */
    private function generate(array $matrix): array {
        $keys = array_keys($matrix);
        $values = array_values($matrix);
        $result = [];
        $totalKeys = count($keys);

        $build = function(int $index, array $currentCombination) use (&$build, &$result, $keys, $values, $totalKeys) {
            if ($index === $totalKeys) {
                $result[] = $currentCombination;
                return;
            }

            foreach ($values[$index] as $val) {
                $currentCombination[$keys[$index]] = $val;
                $build($index + 1, $currentCombination);
            }
        };

        if ($totalKeys > 0) $build(0, []);
        return $result;
    }

    /**
     * Walidator parametrów.
     */
    private function validateField($value, array $rules): bool {
        foreach ($rules as $rule) {
            $param = null;
            if (preg_match("/(.*?)\[(.*?)\]/", $rule, $match)) {
                $rule = $match[1];
                $param = $match[2];
            }

            switch ($rule) {
                case 'required': if ($value === null || $value === '') return false; break;
                case 'numeric':  if (!is_numeric($value)) return false; break;
                case 'integer':  if (!filter_var($value, FILTER_VALIDATE_INT) && $value !== 0) return false; break;
                case 'decimal':  if (!is_numeric($value) || floor($value) == $value) return false; break;
                case 'natural':  if (!ctype_digit((string)$value)) return false; break;
                case 'natural_no_zero': if (!ctype_digit((string)$value) || (int)$value === 0) return false; break;
                case 'min':      if (!is_numeric($value) || $value < (float)$param) return false; break;
                case 'max':      if (!is_numeric($value) || $value > (float)$param) return false; break;
                case 'val':
                    $allowed = explode(',', $param);
                    if (!in_array((string)$value, $allowed)) return false;
                    break;
            }
        }
        return true;
    }
}
