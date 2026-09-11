<?php
declare(strict_types=1);
namespace MrPresident\Engine;

/** Saved campaign milestones. Deliberately consumes no RNG from the event stream. */
final class CampaignSystem
{
    const DEFAULTS = ['status' => 'active', 'reelected' => null, 'house' => 238,
        'senate_classes' => [18, 18, 17], 'results' => [], 'legacy' => null];

    public static function requireActive(GameState $state): void
    {
        if ('active' !== $state->get('campaign.status', 'active')) {
            throw new EngineException('campaign_finished', 'This presidency has ended. Start a new administration to play again.');
        }
    }

    public static function tick(GameState $state): array
    {
        $turn = $state->turn();
        $approval = (float) $state->get('public.approval', 50);
        $notes = [];
        $results = $state->get('campaign.results', []);
        // November of years 2, 4, 6 and 8 for the January 2001 scenario.
        if (in_array($turn, [23, 47, 71, 95], true) && !isset($results[(string) $turn])) {
            $house = (int) $state->get('campaign.house', 238);
            $classes = $state->get('campaign.senate_classes', [18, 18, 17]);
            $class = (int) (($turn - 23) / 24) % 3;
            $sizes = [33, 33, 34];
            // Below 50 always loses seats (until zero); the margin determines severity.
            $houseSwing = $approval < 50 ? -(int) ceil((50 - $approval) * 2) : (int) floor(($approval - 50) * 1.5);
            $senateSwing = $approval < 50 ? -(int) ceil((50 - $approval) / 5) : (int) floor(($approval - 50) / 5);
            $newHouse = max(0, min(435, $house + $houseSwing));
            $beforeSenate = array_sum($classes);
            $classes[$class] = max(0, min($sizes[$class], $classes[$class] + $senateSwing));
            $state->set('campaign.house', $newHouse);
            $state->set('campaign.senate_classes', $classes);
            $result = ['turn' => $turn, 'date' => $state->date(), 'approval' => $approval,
                'type' => in_array($turn, [23, 71], true) ? 'Midterm election' : 'General election',
                'house' => $newHouse, 'senate' => array_sum($classes),
                'house_change' => $newHouse - $house, 'senate_change' => array_sum($classes) - $beforeSenate,
                'senate_contested' => $sizes[$class]];
            if (47 === $turn) {
                $won = $approval > 50;
                $state->set('campaign.reelected', $won);
                $result['presidential_result'] = $won ? 'Reelected' : 'Defeated';
                $notes[] = $won ? 'You won reelection. A second term awaits in January.' : 'You lost reelection. Your term ends in January.';
            }
            $results[(string) $turn] = $result;
            $state->set('campaign.results', $results);
            $notes[] = sprintf('%s: your coalition holds %d House seats and %d Senate seats.', $result['type'], $newHouse, array_sum($classes));
        }
        if ($turn >= 97 || ($turn >= 49 && false === $state->get('campaign.reelected'))) {
            $status = $turn >= 97 ? 'completed' : 'defeated';
            $state->set('campaign.status', $status);
            $state->set('active_event', null);
            $state->set('term', $status === 'completed' ? 2 : 1);
            $state->set('campaign.legacy', [
                'president' => (string) $state->get('president_name'), 'date' => $state->date(),
                'months_served' => $status === 'completed' ? 96 : 48,
                'approval' => $approval, 'decisions' => count($state->get('memories', [])),
                'indicators' => $state->get('public', []),
                'summary' => $status === 'completed'
                    ? 'Eight years in office. Your administration is complete; its record now belongs to history.'
                    : 'The peaceful transfer of power ends your presidency after one term. Your decisions remain in the public record.',
            ]);
            $notes[] = 'Your presidency has ended. The Presidential Legacy Report is ready.';
        }
        return $notes;
    }
}
