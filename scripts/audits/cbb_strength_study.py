"""Offline CBB strength experiment. Reads a production extract; never writes to the application.

The input contains teams, games, prior_lineups, rosters, cohort_rows, forecasts and
result_timings. Historical roster membership is hindsight evidence. This tool is
not a production calculator or an observed-pregame performance report.
"""

import argparse
import collections
import hashlib
import json
import statistics
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np

FEATURES = ["points_per40", "shots_per40", "assists_per40", "turnovers_per40", "rebounds_per40"]
PLAYER_SIGNS = np.array([1, -1, 1, -1, 1])


def timestamp(value):
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=ZoneInfo("America/Chicago"))
    return parsed.astimezone(timezone.utc)


def peer_group(team):
    if team.get("division") in ["NCAA Division I", "Division I"] or team.get("conference") == "NCAA Division I":
        return "division_i"
    if team.get("conference") == "Non-NCAA Division I" or team.get("division") in ["NCAA Division II", "NCAA Division III", "Division II", "Division III"]:
        return "non_division_i"
    return None


def score_edges(games):
    edges = collections.defaultdict(list)
    for game in games:
        margin = game[6] - game[7] - (0 if game[10] else 3.5)
        edges[game[4]].append((game[5], margin))
        edges[game[5]].append((game[4], -margin))
    return edges


def solve_scores(edges, prior=None, weight=0):
    prior = prior or {}
    ratings = dict(prior)
    for team in edges:
        ratings.setdefault(team, 0.0)
    for _ in range(200):
        updated = dict(ratings)
        for team, opponents in edges.items():
            total = weight * prior.get(team, 0) + sum(margin + ratings.get(opponent, 0) for opponent, margin in opponents)
            updated[team] = (ratings[team] + total / (weight + len(opponents))) / 2
        change = max((abs(updated[team] - ratings[team]) for team in updated), default=0)
        ratings = updated
        if change < 0.001:
            break
    return ratings


def sparse_tail_value(value, count, bounds):
    if count >= 8 or bounds is None:
        return value
    bounded = float(np.clip(value, *bounds))
    return bounded + count / 8 * (value - bounded)


def prior_ratings(games, teams, mode="baseline", offseason=True):
    raw = solve_scores(score_edges(games))
    counts = collections.Counter(team for game in games for team in game[4:6])
    centers, bounds = {}, {}
    for group in ["division_i", "non_division_i"]:
        values = [value for team, value in raw.items() if peer_group(teams[team]) == group]
        if len(values) >= 8:
            centers[group] = round(statistics.median(values), 6)
            bounds[group] = np.quantile(values, [0.05, 0.95])
    small = [value for team, value in raw.items() if counts[team] < 8]
    sparse = statistics.median(small) if len(small) >= 8 else None
    if sparse is not None:
        bounds[None] = np.quantile(small, [0.05, 0.95])
    result = {}
    for team, profile in teams.items():
        group = peer_group(profile)
        center = centers.get(group, sparse if sparse is not None else 0)
        if team in raw:
            value = raw[team]
            if mode == "tail_sparse":
                value = sparse_tail_value(value, counts[team], bounds.get(group))
            elif mode == "linear_sparse" and counts[team] < 8:
                value = center + counts[team] / 8 * (value - center)
            result[team] = center + (0.7 if offseason else 1) * (value - center)
        elif group in centers or (group is None and sparse is not None):
            result[team] = center
    return result, counts


def allocate_minutes(weights):
    ordered = sorted(weights, key=lambda player: (-weights[player], player))
    caps = {player: 36 if rank < 5 else 24 if rank < 9 else 8 for rank, player in enumerate(ordered)}
    result = dict.fromkeys(ordered, 0.0)
    remaining = set(ordered)
    left = 200.0
    while remaining and left > 1e-8:
        total = sum(max(0.01, weights[player]) for player in remaining)
        offers = {player: left * max(0.01, weights[player]) / total for player in remaining}
        full = [player for player in remaining if offers[player] >= caps[player] - result[player]]
        if not full:
            for player in remaining:
                result[player] += offers[player]
            break
        for player in full:
            amount = caps[player] - result[player]
            result[player] += amount
            left -= amount
            remaining.remove(player)
    return result


def histories(lineups):
    output = collections.defaultdict(list)
    for lineup in lineups:
        game = lineup["game"]
        for team, players in lineup["sides"].items():
            total = sum(player[1] for player in players)
            output[int(team)].append({"id": game[0], "day": game[1], "players": {player[0]: player[1] * 200 / total for player in players}})
    for rows in output.values():
        rows.sort(key=lambda row: (row["day"], row["id"]))
    return output


def earlier(rows, day):
    return [row for row in rows if row["day"] < day]


def average_minutes(rows, players):
    return {player: sum(row["players"].get(player, 0) for row in rows) / len(rows) for player in players} if rows else dict.fromkeys(players, 0.0)


def role_projection(rows, players, sources=None):
    weights = {}
    for player in players:
        source = sources.get(player, []) if sources is not None else rows
        if not source or not any(row["players"].get(player, 0) > 0 for row in source):
            weights[player] = 8.0
            continue
        source = sorted(source, key=lambda row: (row["day"], row["id"]))
        average = sum(row["players"].get(player, 0) for row in source) / len(source)
        recent = source[-5:]
        weights[player] = (3 * average + sum(row["players"].get(player, 0) for row in recent)) / (3 + len(recent))
    return allocate_minutes(weights)


def production_vector(player):
    _, minutes, points, attempts, free_throws, assists, turnovers, rebounds = player
    return np.array([minutes, points, attempts + 0.44 * free_throws, assists, turnovers, rebounds], dtype=float)


def quality_features(total, league):
    return 40 * (total[1:] + 200 * league) / (total[0] + 200)


def fit_coefficients(rows, scale, penalty):
    design = np.array([row["features"] for row in rows]) / scale * PLAYER_SIGNS
    target = np.array([row["actual"] - row["baseline"] for row in rows])
    matrix = design.T @ design + penalty * np.eye(5)
    rhs = design.T @ target
    coefficients = np.zeros(5)
    for _ in range(2000):
        previous = coefficients.copy()
        for column in range(5):
            coefficients[column] = max(0, (rhs[column] - np.dot(matrix[column], coefficients) + matrix[column, column] * coefficients[column]) / matrix[column, column])
        if np.max(abs(coefficients - previous)) < 1e-10:
            break
    return coefficients * PLAYER_SIGNS / scale


def feature_adjustment(features, coefficients):
    return float(np.clip(np.dot(features, coefficients), -4, 4))


def error(rows, field):
    return statistics.mean(abs(row[field] - row["actual"]) for row in rows)


def fit_player_model(dataset, history):
    games = dataset["games"]
    lineups = dataset["prior_lineups"]
    days = collections.defaultdict(list)
    complete_by_day = collections.defaultdict(list)
    for lineup in lineups:
        days[lineup["game"][1]].append(lineup)
        if len(lineup["sides"]) == 2:
            complete_by_day[lineup["game"][1]].append(lineup)
    totals, league_total, rows = {}, np.zeros(6), []
    for day in sorted(days):
        league = league_total[1:] / league_total[0] if league_total[0] > 0 else np.zeros(5)
        contexts = {}
        involved = {int(team) for lineup in days[day] for team in lineup["sides"]}
        for team in involved:
            past = earlier(history.get(team, []), day)
            if len(past) < 8:
                continue
            players = sorted({player for row in past for player, minutes in row["players"].items() if minutes > 0})
            projected = role_projection(past, players)
            if abs(sum(projected.values()) - 200) > 1e-6:
                continue
            reference = average_minutes(past, players)
            denominator = sum(reference.values())
            reference = {player: value * 200 / denominator for player, value in reference.items()}
            features = {player: quality_features(totals[player], league) if player in totals else 40 * league for player in players}
            contexts[team] = sum((projected.get(player, 0) - reference.get(player, 0)) / 200 * features[player] for player in players)
        past_games = [game for game in games if game[2] == 2026 and game[8] < day and game[6] != game[7]]
        ratings = solve_scores(score_edges(past_games))
        counts = collections.Counter(team for game in past_games for team in game[4:6])
        for lineup in complete_by_day[day]:
            game = lineup["game"]
            home, away = game[2:4]
            if home not in contexts or away not in contexts or min(counts[home], counts[away]) < 8:
                continue
            rows.append({"game_id": game[0], "day": day, "features": (contexts[home] - contexts[away]).tolist(), "baseline": ratings[home] - ratings[away] + (0 if game[6] else 3.5), "actual": game[4] - game[5]})
        for lineup in days[day]:
            for players in lineup["sides"].values():
                for player in players:
                    if player[1] <= 0:
                        continue
                    totals.setdefault(player[0], np.zeros(6))
                    vector = production_vector(player)
                    totals[player[0]] += vector
                    league_total += vector
    train = [row for row in rows if row["day"] < "2026-01-01"]
    validation = [row for row in rows if "2026-01-01" <= row["day"] < "2026-02-01"]
    test = [row for row in rows if row["day"] >= "2026-02-01"]
    if not train or not validation or not test:
        raise ValueError("The prior-season training, validation and test periods must all be present.")
    scale = np.maximum(np.std([row["features"] for row in train], axis=0), 0.001)
    choices = []
    for penalty in [1, 10, 100, 1000]:
        coefficients = fit_coefficients(train, scale, penalty)
        for row in validation:
            row["candidate"] = row["baseline"] + feature_adjustment(row["features"], coefficients)
        choices.append({"penalty": penalty, "validation_mae": error(validation, "candidate")})
    null_error = error(validation, "baseline")
    selected = min(choices, key=lambda choice: choice["validation_mae"])
    coefficients = fit_coefficients(train + validation, scale, selected["penalty"]) if selected["validation_mae"] < null_error else np.zeros(5)
    for row in test:
        row["candidate"] = row["baseline"] + feature_adjustment(row["features"], coefficients)
    summary = {"train_n": len(train), "validation_n": len(validation), "test_n": len(test), "choices": choices, "validation_null_mae": null_error, "selected_penalty": selected["penalty"] if selected["validation_mae"] < null_error else None, "test_baseline": error(test, "baseline"), "test_candidate": error(test, "candidate"), "coefficients": dict(zip(FEATURES, coefficients.tolist()))}
    return coefficients, totals, league_total, summary


def player_contexts(dataset, history, coefficients, totals, league_total):
    league = league_total[1:] / league_total[0]
    features = {player: quality_features(total, league) for player, total in totals.items()}
    sources = collections.defaultdict(list)
    for past in history.values():
        for player in {player for row in past for player, minutes in row["players"].items() if minutes > 0}:
            sources[player].extend(past)
    result = {}
    for team_text, players in dataset["rosters"].items():
        team = int(team_text)
        past = history.get(team, [])
        old_players = sorted({player for row in past for player, minutes in row["players"].items() if minutes > 0})
        if not past or not old_players:
            continue
        projected = role_projection(past, players, sources)
        reference_weights = average_minutes(past, old_players)
        denominator = sum(reference_weights.values())
        reference = sum(value * features.get(player, 40 * league) for player, value in reference_weights.items()) / denominator
        predicted = sum(value * features.get(player, reference) for player, value in projected.items()) / 200
        measured = lambda player: totals.get(player, np.zeros(6))[0] >= 80
        coverage = sum(value for player, value in projected.items() if measured(player)) / 200
        eligible = len(past) >= 8 and sum(measured(player) for player in players) >= 5 and coverage >= 0.6 and abs(sum(projected.values()) - 200) < 1e-6
        result[team] = {"eligible": bool(eligible), "coverage": coverage, "features": (predicted - reference).tolist(), "adjustment": feature_adjustment(predicted - reference, coefficients)}
    return result


def known_results(dataset, target, asof):
    rows = []
    for game in dataset["games"]:
        if game[2] != 2027 or game[8] >= target[8] or game[6] == game[7]:
            continue
        revisions = [revision for revision in dataset["result_timings"].get(str(game[0]), []) if max(timestamp(revision["created_at"]), timestamp(revision["observed_at"]), timestamp(revision["finalized_at"])) <= timestamp(asof)]
        if revisions:
            result = max(revisions, key=lambda revision: revision["revision"])
            copy = list(game)
            copy[6], copy[7] = result["home_score"], result["away_score"]
            rows.append(copy)
    return rows


def summarize(rows):
    summary = []
    for cohort in sorted({row["cohort"] for row in rows} | {"all"}):
        selected = [row for row in rows if cohort == "all" or row["cohort"] == cohort]
        if not selected:
            continue
        summary.append({
            "cohort": cohort,
            "n": len(selected),
            "player_eligible": sum(row["player_eligible"] for row in selected),
            **{field: error(selected, field) for field in ["baseline", "player", "sparse", "combined"]},
        })
    return summary


def uncertainty(rows, replicates=10000):
    rng = np.random.default_rng(4917)
    reports = []
    for cohort in ["all", *sorted({row["cohort"] for row in rows})]:
        selected = [row for row in rows if cohort == "all" or row["cohort"] == cohort]
        teams = sorted({row[key] for row in selected for key in ["home_team_id", "away_team_id"]})
        index = {team: offset for offset, team in enumerate(teams)}
        home = np.array([index[row["home_team_id"]] for row in selected])
        away = np.array([index[row["away_team_id"]] for row in selected])
        difference = np.array([abs(row["combined"] - row["actual"]) - abs(row["baseline"] - row["actual"]) for row in selected])
        team_weights = rng.poisson(1, (replicates, len(teams)))
        game_weights = team_weights[:, home] * team_weights[:, away]
        total = game_weights.sum(axis=1)
        usable = total > 0
        bootstrap = (game_weights @ difference)[usable] / total[usable]
        reports.append({"cohort": cohort, "n": len(selected), "paired_mae_difference": float(difference.mean()), "team_weight_bootstrap95": np.quantile(bootstrap, [0.025, 0.975]).tolist(), "improved": int(sum(difference < 0)), "worsened": int(sum(difference > 0))})
    return {"method": "Exploratory Poisson weights per team; game weight=home*away. Repeated-team sensitivity, not proof of prospective benefit.", "replicates": replicates, "seed": 4917, "results": reports}


def sparse_validation(games, teams):
    blocks = [("2025-12-01", "2026-01-01"), ("2026-01-01", "2026-02-01"), ("2026-02-01", "2026-03-01"), ("2026-03-01", "2026-04-22")]
    result = []
    for start, end in blocks:
        training = [game for game in games if game[8] < start]
        targets = [game for game in games if start <= game[8] < end]
        for mode in ["baseline", "tail_sparse", "linear_sparse"]:
            ratings, counts = prior_ratings(training, teams, mode, offseason=False)
            rows = []
            for game in targets:
                home, away = game[4:6]
                if home not in ratings or away not in ratings:
                    continue
                rows.append({"actual": game[6] - game[7], "prediction": ratings[home] - ratings[away] + (0 if game[10] else 3.5), "sparse": min(counts[home], counts[away]) < 8})
            small = [row for row in rows if row["sparse"]]
            result.append({"start": start, "end": end, "mode": mode, "n": len(rows), "mae": error(rows, "prediction"), "sparse_n": len(small), "sparse_mae": error(small, "prediction") if small else None})
    return result


def validate_dataset(dataset):
    if dataset.get("schema_version") != 1:
        raise ValueError("Expected CBB strength study dataset schema_version=1.")
    teams = {team["id"] for team in dataset["teams"]}
    games = {game[0]: game for game in dataset["games"]}
    if len(games) != len(dataset["games"]):
        raise ValueError("Duplicate game IDs in dataset.")
    cohort_ids = [row["game_id"] for row in dataset["cohort_rows"]]
    if len(cohort_ids) != len(set(cohort_ids)):
        raise ValueError("Duplicate target games would change cohort weights.")
    for game in games.values():
        if len(game) != 11 or game[2] not in [2026, 2027] or not set(game[4:6]) <= teams:
            raise ValueError("Games must use the explicit 2026/2027 study contract and known teams.")
    for game_id in cohort_ids:
        if game_id not in games or games[game_id][2] != 2027:
            raise ValueError("Targets must be current-season games.")
    for lineup in dataset["prior_lineups"]:
        game = games.get(lineup["game"][0])
        if game is None or game[2] != 2026 or game[8] != lineup["game"][1]:
            raise ValueError("Player training boxes must match prior-season game identity and date.")
        if game[4:8] != lineup["game"][2:6] or bool(game[10]) != bool(lineup["game"][6]):
            raise ValueError("Player training boxes disagree with the stored matchup or score.")
        for team, players in lineup["sides"].items():
            team = int(team)
            if team not in game[4:6] or len({player[0] for player in players}) != len(players):
                raise ValueError("Invalid player team membership or duplicate player box rows.")
            if any(len(player) != 8 or any(value < 0 for value in player[1:]) or player[1] > 60 for player in players):
                raise ValueError("Invalid player production or minutes.")
            score = game[6] if team == game[4] else game[7]
            if sum(player[2] for player in players) != score or not 190 <= sum(player[1] for player in players) <= 300:
                raise ValueError("Player points or minutes do not reconcile with the team game.")
    for team, players in dataset["rosters"].items():
        if int(team) not in teams or len(players) < 5 or len(set(players)) != len(players):
            raise ValueError("Invalid roster membership; unavailable teams must be omitted.")


def run(dataset):
    validate_dataset(dataset)
    teams = {int(team["id"]): team for team in dataset["teams"]}
    games = {game[0]: game for game in dataset["games"]}
    prior_games = [game for game in games.values() if game[2] == 2026 and game[6] != game[7]]
    history = histories(dataset["prior_lineups"])
    coefficients, totals, league_total, player_validation = fit_player_model(dataset, history)
    contexts = player_contexts(dataset, history, coefficients, totals, league_total)
    priors = {mode: prior_ratings(prior_games, teams, mode)[0] for mode in ["baseline", "tail_sparse", "linear_sparse"]}
    forecast_times = {row["game_id"]: row["generated_at"] for row in dataset["forecasts"]}
    cache, rows = {}, []
    for original in dataset["cohort_rows"]:
        game = games[original["game_id"]]
        asof = forecast_times.get(game[0], game[8] + "T" + (game[9] or "00:00:00") + "+00:00")
        key = (game[8], asof)
        if key not in cache:
            known = known_results(dataset, game, asof)
            cache[key] = ({mode: solve_scores(score_edges(known), prior, 8) for mode, prior in priors.items()}, collections.Counter(team for row in known for team in row[4:6]))
        ratings, counts = cache[key]
        home, away = game[4:6]
        values = {mode: ratings[mode][home] - ratings[mode][away] + (0 if game[10] else 3.5) for mode in priors}
        h, a = contexts.get(home, {}), contexts.get(away, {})
        eligible = h.get("eligible", False) and a.get("eligible", False)
        delta = 0.7 * (8 * h["adjustment"] / (8 + counts[home]) - 8 * a["adjustment"] / (8 + counts[away])) if eligible else 0
        rows.append({"game_id": game[0], "home_team_id": home, "away_team_id": away, "cohort": original["cohort"], "name": teams[away]["school"] + " @ " + teams[home]["school"], "actual": game[6] - game[7], "baseline": values["baseline"], "player": values["baseline"] + delta, "sparse": values["tail_sparse"], "combined": values["tail_sparse"] + delta, "broad_shrinkage": values["linear_sparse"], "player_eligible": bool(eligible)})
    return {"retrospective": True, "pregame_safe": False, "production_applied": False, "limitations": ["Roster observations were collected after earlier target games.", "Mutable historical player rows do not establish as-observed availability.", "Previously examined current-season cohorts are not pristine holdouts.", "Sparse prior-season diagnostics hold monthly starting ratings fixed; they are not full production replays.", "Inferred rotation roles are not confirmed starting lineups; defensive player metrics are incomplete."], "player_validation": player_validation, "sparse_validation": sparse_validation(prior_games, teams), "summary": summarize(rows), "uncertainty": uncertainty(rows), "rows": rows, "player_contexts": contexts}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    if args.dataset.resolve() == args.output.resolve():
        parser.error("Output must differ from the source dataset.")
    raw = args.dataset.read_bytes()
    dataset = json.loads(raw)
    result = run(dataset)
    result["source_sha256"] = hashlib.sha256(raw).hexdigest()
    result["study_sha256"] = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    result["source"] = dataset.get("source", {})
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")
    print(json.dumps({"retrospective": True, "summary": result["summary"], "player_validation": result["player_validation"]}, indent=2))


if __name__ == "__main__":
    main()
