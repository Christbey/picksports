"""Explicit CFB feature policy and retrospective performance expectancy.

Postgame expectancy conditions on observed regulation performance, not final scores.
It is a descriptive estimate, never a pregame forecast or a calibrated probability.
"""
import math

POLICY = "cfb-efficiency-context-v2"


def number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)


def drive_performance(drive):
    plays = drive.get("play_observations", [])
    eligible = [p for p in plays if p.get("kind") in ("pass", "rush")
                and all(number(p.get(k)) for k in ("down", "distance", "yards"))
                and p["down"] in (1, 2, 3, 4) and p["distance"] > 0]
    if not eligible or len(eligible) != len(plays):
        return None
    n = len(eligible)
    success = sum(p["yards"] >= p["distance"] * (.5 if p["down"] == 1 else .7 if p["down"] == 2 else 1) for p in eligible)
    explosive = sum(p["yards"] >= (20 if p["kind"] == "pass" else 10) for p in eligible)
    # Exclude EPA, turnovers, actual points and team identity from the postgame fit.
    # Drive length and success can still indirectly reflect turnover-shortened drives.
    return {"success_rate": success/n, "explosive_rate": explosive/n,
            "yards_per_play": sum(p["yards"] for p in eligible)/n,
            "plays": n, "sack_rate": sum(bool(p.get("sack")) for p in eligible)/n}


def contextual_features(row, side):
    context = row.get("context", {}).get(side, {})
    allowed = ("qb_changed", "out_records", "questionable_records", "rest_travel_fatigue",
               "returning_usage", "talent", "wind_speed_mph", "precipitation_inches")
    result = {}
    for key in allowed:
        value = context.get(key)
        result["context:"+key+":missing"] = float(not number(value))
        if number(value):
            result["context:"+key] = value
    return result


def stadium_features(row, side):
    if row.get("neutral", False) or not row.get("venue"):
        return {}
    # The same venue affects the host offense and visiting offense separately.
    return {"venue:"+str(row["venue"])+":"+side: 1.0}


def pace_features(row, side):
    other = "away" if side == "home" else "home"
    return {"team:"+str(row[side+"_id"]): 1.0,
            "opponent:"+str(row[other+"_id"]): 1.0}


def postgame_expectancy(artifact, row, predict, tilt):
    from scoring import stamp
    if str(row["game_id"]) in {str(value) for value in artifact.get("training_ids", [])} or stamp(row["kickoff"]) <= stamp(artifact["trained_before"]):
        raise ValueError("Postgame artifact must exclude target and precede kickoff")
    model = artifact.get("models", {}).get("postgame_performance")
    if not model:
        raise ValueError("No supported postgame performance model")
    if artifact.get("evidence_mode") != "retrospective" and stamp(artifact["max_source_available_at"]) >= stamp(row["kickoff"]):
        raise ValueError("Postgame training sources were unavailable before target")
    scores = {"home": {0: 1.0}, "away": {0: 1.0}}
    counts = {"home": 0, "away": 0}
    expected = {"home": 0.0, "away": 0.0}
    for drive in row["drives"]:
        if drive["period"] > 4:
            continue
        if str(drive["offense_id"]) not in (str(row["home_id"]), str(row["away_id"])):
            raise ValueError("Unknown postgame offense")
        x = drive_performance(drive)
        if x is None:
            # Do not omit punts/returns/short possessions and report full coverage.
            raise ValueError("Incomplete postgame regulation play evidence")
        side = "home" if str(drive["offense_id"]) == str(row["home_id"]) else "away"
        outcomes = tilt(artifact["postgame_outcomes"], predict(model, x))
        updated = {}
        for score, probability in scores[side].items():
            for outcome in outcomes:
                points = score + outcome["points"]
                updated[points] = updated.get(points, 0.0) + probability*outcome["probability"]
        scores[side] = updated
        counts[side] += 1
        expected[side] += sum(o["points"]*o["probability"] for o in outcomes)
    if min(counts.values()) < 5:
        raise ValueError("Insufficient postgame possessions for both teams")
    win = tie = 0.0
    for h, hp in scores["home"].items():
        for a, ap in scores["away"].items():
            win += hp*ap if h > a else 0
            tie += hp*ap if h == a else 0
    return {"game_id": row["game_id"], "method": "observed_efficiency_regulation_drive_distribution_v1",
            "home_win_expectancy": win + tie/2, "regulation_tie_probability": tie,
            "expected_home_points": expected["home"], "expected_away_points": expected["away"],
            "possessions": counts, "status": "uncalibrated_postgame_diagnostic",
            "target_score_used": False, "direct_turnover_and_epa_inputs_used": False,
            "limitations": ["Observed drive count, yards and success retain indirect turnover and score-state effects.",
                            "Field position is marginalized through historical outcomes; special teams are not modeled separately.",
                            "Regulation ties receive half credit, not an overtime win forecast."]}
