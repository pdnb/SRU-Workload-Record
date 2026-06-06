<?php

namespace App\Services;

class PmsWorkloadHtmlParser
{
    /**
     * @return list<array{name: string, max_score: float, mains: array<string, list<array{name: string, score: float}>>}>
     */
    public function parse(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rowMatches);

        $groups = [];
        $currentGroupIndex = null;
        $currentMainIndex = null;

        foreach ($rowMatches[1] as $row) {
            if (preg_match(
                '/csB7F1E620" colspan="2".*?<nobr>(\d+)\.<\/nobr>.*?cs1C211F4B.*?<nobr>(.*?)<\/nobr>.*?csB7F1E620" style="width:63px.*?<nobr>([\d,]+)<\/nobr>/s',
                $row,
                $m
            )) {
                $name = $this->decodeHtmlText($m[2]);

                if (str_starts_with($name, 'รวม')) {
                    continue;
                }

                $groups[] = [
                    'name' => $name,
                    'max_score' => (float) str_replace(',', '', $m[3]),
                    'mains' => [],
                ];
                $currentGroupIndex = count($groups) - 1;
                $currentMainIndex = null;

                continue;
            }

            if (preg_match(
                '/cs34886720" colspan="3".*?<nobr>(\d+\.\d+)<\/nobr>.*?cs644C7FBE.*?<nobr>(.*?)<\/nobr>/s',
                $row,
                $m
            )) {
                if (! isset($currentGroupIndex)) {
                    continue;
                }

                $groups[$currentGroupIndex]['mains'][] = [
                    'name' => $this->decodeHtmlText($m[2]),
                    'workloads' => [],
                ];
                $currentMainIndex = count($groups[$currentGroupIndex]['mains']) - 1;

                continue;
            }

            if (preg_match(
                '/csA1D0E606.*?<nobr>(\d+\.\d+\.\d+)<\/nobr>.*?cs1D4EAD0B" colspan="4".*?csDA334680">(.*?)<\/span>.*?cs34886720" rowspan="\d+" style="width:94px.*?<nobr>([\d,]+)<\/nobr>/s',
                $row,
                $m
            )) {
                if ($currentMainIndex === null) {
                    continue;
                }

                $groups[$currentGroupIndex]['mains'][$currentMainIndex]['workloads'][] = [
                    'name' => $this->decodeHtmlText($m[2]),
                    'score' => (float) str_replace(',', '', $m[3]),
                ];

                continue;
            }

            if (preg_match(
                '/csA1D0E606.*?<nobr>(\d+\.\d+\.\d+)<\/nobr>.*?cs1D4EAD0B" colspan="4".*?csDA334680">(.*?)<\/span>.*?cs34886720" style="width:94px.*?<nobr>([\d,]+)<\/nobr>/s',
                $row,
                $m
            )) {
                if ($currentMainIndex === null) {
                    continue;
                }

                $groups[$currentGroupIndex]['mains'][$currentMainIndex]['workloads'][] = [
                    'name' => $this->decodeHtmlText($m[2]),
                    'score' => (float) str_replace(',', '', $m[3]),
                ];
            }
        }

        return array_map(function (array $group) {
            $mains = [];
            foreach ($group['mains'] as $main) {
                $mains[$main['name']] = $main['workloads'];
            }

            return [
                'name' => $group['name'],
                'max_score' => $group['max_score'],
                'mains' => $mains,
            ];
        }, $groups);
    }

    /**
     * @param  list<array{name: string, max_score: float, mains: array<string, list<array{name: string, score: float}>>}>  $structure
     * @return array{groups: int, mains: int, workloads: int}
     */
    public function stats(array $structure): array
    {
        $groupCount = count($structure);
        $mainCount = array_sum(array_map(fn ($g) => count($g['mains']), $structure));
        $workloadCount = array_sum(array_map(
            fn ($g) => array_sum(array_map(fn ($w) => count($w), $g['mains'])),
            $structure
        ));

        return [
            'groups' => $groupCount,
            'mains' => $mainCount,
            'workloads' => $workloadCount,
        ];
    }

    protected function decodeHtmlText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
