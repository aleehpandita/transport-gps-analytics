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
 * Puerto) o como iniciales de palabras seguidas ("MC" de Mar Caribe). Al menos una palabra
 * de la orden que no sea de zona tiene que coincidir completa o con un error de dedo: las
 * iniciales y las zonas solas no bastan (5 nov: "SIS PM" se iba a Naay Puerto Morelos por
 * "PM", e "Iberostar Riviera Cancún" a Haven Riviera Cancún por "Riviera"). Gana el lugar que
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
    // Ciudades y zonas: una orden que solo dice "PDC" o "Playa del Carmen" no es un lugar
    // del catálogo (4 nov: "Playa del Carmen" se emparejaba con el Complejo Princess)
    protected const ZONES = ['playa', 'carmen', 'pdc', 'tulum', 'puerto', 'morelos', 'aventuras',
        'centro', 'zona', 'hotelera', 'isla', 'mujeres', 'akumal', 'holbox', 'riviera', 'maya'];

    /**
     * @param list<array{id: int, name: string}> $places
     * @return int|null id del lugar
     */
    public function match(string $text, array $places): ?int
    {
        $wanted = $this->words($text);
        if (!$wanted || !array_diff($wanted, self::ZONES)) {
            return null;
        }

        $scores = [];
        foreach ($places as $place) {
            $words = $this->words($place['name']);
            if (!$words) {
                continue;
            }
            $hits = 0;
            $strong = false;
            foreach (array_diff($wanted, self::ZONES) as $w) {
                if ($this->explains($w, $words)) {
                    $hits++;
                    $strong = $strong || (strlen($w) >= 3 && !in_array($w, self::ZONES, true) && $this->fullMatch($w, $words));
                }
            }
            if (!$strong) {
                continue;
            }
            // Las de zona desempatan: "Secrets Playa Mujeres" es Secrets Playa Mujeres y
            // no Secrets The Vine, aunque los dos tengan "Secrets"
            $zoneHits = 0;
            foreach (array_intersect($wanted, self::ZONES) as $w) {
                if ($this->fullMatch($w, $words)) {
                    $zoneHits++;
                }
            }
            // Las palabras de zona no cuentan para la proporción: "Aloft Zona Hotelera" se
            // decide por "Aloft"
            $share = $hits / max(1, count(array_diff($wanted, self::ZONES)));
            if ($share >= self::MIN_SHARE) {
                // Desempate: el que tenga menos palabras de sobra ("Moon Palace" es Moon
                // Palace Lobby y no Moon Palace Nizuc)
                $scores[] = [$share, $zoneHits, -count($words), $place['id']];
            }
        }

        if (!$scores) {
            return null;
        }
        rsort($scores);
        if (isset($scores[1]) && abs($scores[1][0] - $scores[0][0]) < 1e-9
            && $scores[1][1] === $scores[0][1] && $scores[1][2] === $scores[0][2]) {
            return null;
        }

        return $scores[0][3];
    }

    /**
     * La palabra aparece completa en el lugar, o con hasta TYPO_LETTERS letras distintas.
     *
     * @param list<string> $words
     */
    protected function fullMatch(string $w, array $words): bool
    {
        foreach ($words as $word) {
            if ($w === $word || (strlen($w) >= 5 && strlen($word) >= 5 && levenshtein($w, $word) <= self::TYPO_LETTERS)) {
                return true;
            }
        }

        return false;
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