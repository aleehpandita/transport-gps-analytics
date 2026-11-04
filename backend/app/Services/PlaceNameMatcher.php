<?php

namespace App\Services;

/**
 * Encuentra en el catálogo el lugar que corresponde a un nombre escrito a mano en una
 * orden, con errores de dedo y abreviaturas. Ejemplos reales de las órdenes de Kevs1:
 *
 *   "Kempinsky"        → Kempinski Hotel Cancún lobby   (una letra distinta)
 *   "Naay P. Morelos"  → Naay Residencial Puerto Morelos (P. = Puerto)
 *   "Hilton MC"        → Hilton Cancun Mar Caribe lobby  (MC = Mar Caribe)
 *
 * Cada palabra de la orden cuenta si coincide con una del lugar: igual, con hasta
 * TYPO_LETTERS letras distintas (palabras de 5 letras o más), como inicial ("P." de
 * Puerto) o como iniciales de palabras seguidas ("MC" de Mar Caribe). Gana el lugar que
 * explica una parte mayor de las palabras de la orden; si dos empatan, no se elige
 * ninguno (mejor marcarlo para revisar que adivinar): "Riu" solo puede ser Riu Caribe o
 * Riu Yucatán.
 *
 * Trabaja sobre arreglos simples para poder probarlo sin base de datos.
 */
class PlaceNameMatcher
{
    // Palabras que no ayudan a distinguir un lugar
    protected const NOISE = ['lobby', 'hotel', 'resort', 'spa', 'all', 'inclusive', 'cancun', 'the', 'and', 'by', 'de', 'del', 'la', 'el', 'los', 'las'];
    protected const TYPO_LETTERS = 1;
    // Mínimo de palabras de la orden que deben coincidir
    protected const MIN_SHARE = 0.5;

    /**
     * @param list<array{id: int, name: string}> $places
     * @return int|null id del lugar
     */
    public function match(string $text, array $places): ?int
    {
        $wanted = $this->words($text);
        if (!$wanted) {
            return null;
        }

        $scores = [];
        foreach ($places as $place) {
            $words = $this->words($place['name']);
            if (!$words) {
                continue;
            }
            $hits = 0;
            foreach ($wanted as $w) {
                if ($this->explains($w, $words)) {
                    $hits++;
                }
            }
            $share = $hits / count($wanted);
            if ($share >= self::MIN_SHARE) {
                $scores[] = [$share, $place['id']];
            }
        }

        if (!$scores) {
            return null;
        }
        rsort($scores);
        if (isset($scores[1]) && abs($scores[1][0] - $scores[0][0]) < 1e-9) {
            return null;
        }

        return $scores[0][1];
    }

    /**
     * @param list<string> $words Palabras del lugar
     */
    protected function explains(string $w, array $words): bool
    {
        foreach ($words as $i => $word) {
            if ($w === $word) {
                return true;
            }
            if (strlen($w) >= 5 && strlen($word) >= 5 && levenshtein($w, $word) <= self::TYPO_LETTERS) {
                return true;
            }
            // Inicial: "p" de puerto
            if (strlen($w) === 1 && $word[0] === $w) {
                return true;
            }
            // Iniciales de palabras seguidas: "mc" de mar caribe
            if (strlen($w) >= 2 && strlen($w) <= 4 && $i + strlen($w) <= count($words)) {
                $initials = '';
                for ($k = 0; $k < strlen($w); $k++) {
                    $initials .= $words[$i + $k][0];
                }
                if ($initials === $w) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function words(string $text): array
    {
        // Acentos a mano: iconv translitera distinto en Mac y en Linux ("Yucatán" salía
        // "yucat'an" en Mac y ya no coincidía con "Yucatan")
        $plain = strtr(mb_strtolower($text, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e',
            'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ç' => 'c',
        ]);
        $words = preg_split('/[^a-z0-9]+/', $plain, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($words, self::NOISE));
    }
}