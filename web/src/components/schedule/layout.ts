import { VisitListItem } from '@/components/visits/types';

export interface PositionedVisit {
    visit: VisitListItem;
    start: Date;
    end: Date;
    lane: number;
    laneCount: number;
}

/**
 * Assign side-by-side lanes to one day's visits: overlapping visits form a
 * cluster; within it each visit takes the first lane free at its start, and
 * every member shares the cluster's lane count so widths line up.
 */
export function layoutDay(visits: VisitListItem[]): PositionedVisit[] {
    const items = visits
        .filter((v) => v.startsAt && v.endsAt)
        .map((visit) => ({ visit, start: new Date(visit.startsAt!), end: new Date(visit.endsAt!), lane: 0, laneCount: 1 }))
        // Stable order (id tiebreak) so lanes don't reshuffle between renders.
        .sort((a, b) => a.start.getTime() - b.start.getTime() || a.visit.id - b.visit.id);

    const positioned: PositionedVisit[] = [];
    let cluster: PositionedVisit[] = [];
    let laneEnds: number[] = [];
    let clusterEnd = -Infinity;

    const flush = () => {
        for (const p of cluster) p.laneCount = laneEnds.length;
        positioned.push(...cluster);
        cluster = [];
        laneEnds = [];
        clusterEnd = -Infinity;
    };

    for (const item of items) {
        if (cluster.length > 0 && item.start.getTime() >= clusterEnd) flush();

        let lane = laneEnds.findIndex((end) => end <= item.start.getTime());
        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(0);
        }
        laneEnds[lane] = item.end.getTime();
        item.lane = lane;
        cluster.push(item);
        clusterEnd = Math.max(clusterEnd, item.end.getTime());
    }
    flush();

    return positioned;
}
