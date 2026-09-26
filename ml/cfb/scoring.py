"""Offline CFB challenger. Standard-library-only training; portable JSON artifacts.

Recorded source availability controls training membership. No fitting during PHP
prediction requests. Retrospective holdouts cannot justify promotion; prospective evidence is required.
"""
from __future__ import annotations
import argparse
import collections
import datetime as dt
import hashlib
import json
import math
import random
from pathlib import Path

VERSION = "cfb-possession-v1"


def stamp(value):
    return dt.datetime.fromisoformat(value.replace("Z", "+00:00"))


def week(row):
    date = stamp(row["kickoff"]).date()
    return (date - dt.timedelta(days=date.weekday())).isoformat()


def prior(rows, cutoff, retrospective=False):
    cut = stamp(cutoff)
    return [r for r in rows if stamp(r["kickoff"]) < cut and
            (retrospective or stamp(r["source_available_at"]) < cut)]


def ridge(records, penalty=30.0, iterations=120):
    """Sparse weighted coordinate descent. Intercept unpenalized, all effects pooled."""
    if not records:
        raise ValueError("No eligible training observations")
    columns = collections.defaultdict(list)
    for i, (x, y, weight) in enumerate(records):
        for key, value in {"intercept": 1.0, **x}.items():
            if value:
                columns[key].append((i, float(value), weight))
    residual = [float(y) for _, y, _ in records]
    beta = {key: 0.0 for key in columns}
    for _ in range(iterations):
        maximum = 0.0
        for key, col in columns.items():
            numerator = sum(w * x * (residual[i] + x * beta[key]) for i, x, w in col)
            denominator = sum(w*x*x for _, x, w in col) + (0 if key == "intercept" else penalty)
            value = numerator / max(denominator, 1e-12)
            diff = value - beta[key]
            if diff:
                for i, x, _ in col:
                    residual[i] -= x * diff
            beta[key] = value
            maximum = max(maximum, abs(diff))
        if maximum < 1e-7:
            break
    return {"coefficients": beta, "penalty": penalty, "observations": len(records)}


def predict(model, x):
    coef = model["coefficients"]
    return coef.get("intercept", 0) + sum(v * coef.get(k, 0) for k, v in x.items())


def features(offense, defense, home, neutral=False):
    return {"offense:"+str(offense): 1, "defense:"+str(defense): 1,
            "home": float(home and not neutral)}


def fit(rows, cutoff, penalty=30.0, decay=.8, retrospective=False):
    rows = prior(rows, cutoff, retrospective)
    if len(rows) < 20:
        raise ValueError("Need at least 20 eligible games to fit; retain existing artifact")
    latest = max(r["season"] for r in rows)
    records = {k: [] for k in ("pass", "rush", "pass_epa", "rush_epa", "pass_success", "rush_success", "pass_explosive", "rush_explosive", "sacks", "drive", "pace")}
    samples = collections.Counter()
    outcomes = collections.Counter()
    patterns, pattern_periods, overtime, starts = [], [], [], []
    for row in rows:
        weight = decay ** (latest - row["season"])
        pattern = []
        periods = []
        for d in row["drives"]:
            offense = str(d["offense_id"])
            home = offense == str(row["home_id"])
            defense = str(row["away_id"] if home else row["home_id"])
            if d["period"] > 4:
                overtime.append({"season": row["season"], "round": d["period"]-4,
                                 "points": d["offense_points"], "opponent_points": d["opponent_points"]})
                continue
            x = features(offense, defense, home, row.get("neutral", False))
            # Every drive is a distinct observation; sacks belong to dropbacks upstream.
            observations=d.get("play_observations",[])
            if observations:
                for play in observations:
                    if not all(isinstance(play.get(key),(int,float)) for key in ("down","distance","yards_to_endzone","yards")):
                        continue
                    state={**x,"state_down":play["down"]-1,"state_distance":(play["distance"]-10)/10,
                           "state_field":(play["yards_to_endzone"]-75)/25,
                           "state_margin":play.get("margin",0)/21,"state_late":float(play.get("period",1)==4)}
                    kind=play["kind"]
                    records[kind].append((state,play["yards"],weight))
                    threshold=.5 if play["down"]==1 else (.7 if play["down"]==2 else 1)
                    records[kind+"_success"].append((state,float(play["yards"]>=threshold*play["distance"]),weight))
                    records[kind+"_explosive"].append((state,float(play["yards"]>=(20 if kind=="pass" else 10)),weight))
                    if kind=="pass":
                        records["sacks"].append((state,float(play.get("sack",False)),weight))
                    if isinstance(play.get("epa"),(int,float)):
                        records[kind+"_epa"].append((state,play["epa"],weight))
            else:
                for kind, count, yards in (("pass", d["dropbacks"], d["pass_yards"]),
                                           ("rush", d["rushes"], d["rush_yards"])):
                    if count:
                        records[kind].append((x, yards/count, weight*count))
            state_x={**x,"late_lead":max(0,d.get("start_margin",0))/7 if d["period"]==4 else 0,
                     "late_trail":max(0,-d.get("start_margin",0))/7 if d["period"]==4 else 0}
            records["drive"].append((state_x, d["offense_points"], weight))
            samples[offense] += weight
            outcomes[(d["offense_points"], d["opponent_points"])] += weight
            pattern.append("home" if home else "away")
            periods.append(d["period"])
            starts.append(d.get("start_yards_to_endzone"))
        if not pattern:
            continue
        patterns.append(pattern)
        pattern_periods.append(periods)
        for side in ("home", "away"):
            records["pace"].append(({"team:"+str(row[side+"_id"]): 1}, pattern.count(side), weight))
    models = {name: ridge(data, penalty) for name, data in records.items() if data}
    efficiency_keys=[key for key in models if key not in ("drive","pace")]
    enriched = [({**x, **{key+"_eff":predict(models[key],x) for key in efficiency_keys}}, y, w) for x,y,w in records["drive"]]
    models["drive"] = ridge(enriched, penalty)
    pace_variance = max(1.0, sum(w*(y-predict(models["pace"],x))**2 for x,y,w in records["pace"])/sum(w for _,_,w in records["pace"]))
    fpi_rows=[r for r in rows if all(isinstance(r.get("fpi",{}).get(side),(int,float)) for side in ("home","away"))]
    if len(fpi_rows)>=20:
        for market in ("margin","total"):
            models["fpi_"+market]=ridge([(fpi_features(r), r["home_score"]-r["away_score"] if market=="margin" else r["home_score"]+r["away_score"],decay**(latest-r["season"])) for r in fpi_rows],penalty)
    # Separate pass/rush adjusted estimates are exposed; drive effects are the initial
    # scoring baseline. Only a later held-out learned blend may add their residual value.
    return {"schema": VERSION, "trained_before": cutoff, "training_games": len(rows),
            "training_ids": [r["game_id"] for r in rows], "max_source_available_at": max((r["source_available_at"] for r in rows),key=stamp),
            "evidence_mode": "retrospective" if retrospective else "recorded_time",
            "models": models, "efficiency_keys": efficiency_keys, "pace_variance": pace_variance, "effective_drives": dict(samples), "decay": decay,
            "outcomes": [{"points": p, "opponent_points": q, "weight": w} for (p,q),w in sorted(outcomes.items())],
            "patterns": patterns, "pattern_periods": pattern_periods, "overtime": overtime,
            "calibration_status": "unvalidated", "signal_model": None}


def fpi_features(row):
    h,a=row["fpi"]["home"],row["fpi"]["away"]
    if not isinstance(h,(int,float)) or not isinstance(a,(int,float)):
        raise ValueError("Missing original FPI evidence")
    return {"difference":h-a,"absolute_difference":abs(h-a),"home":float(not row.get("neutral",False))}


def tilt(outcomes, target):
    lower = min(o["points"] for o in outcomes)
    upper = max(o["points"] for o in outcomes)
    target = max(lower+.0001, min(upper-.0001, target)) if upper > lower else lower
    lo, hi = -20.0, 20.0
    weights = []
    for _ in range(60):
        mid = (lo+hi)/2
        logs = [math.log(o["weight"])+mid*o["points"] for o in outcomes]
        largest = max(logs)
        weights = [math.exp(v-largest) for v in logs]
        mean = sum(w*o["points"] for w,o in zip(weights,outcomes))/sum(weights)
        if mean < target:
            lo = mid
        else:
            hi = mid
    total = sum(weights)
    return [{**o, "probability": w/total} for o,w in zip(outcomes, weights)]


def pick(rng, rows, weight="probability"):
    if rows[-1].get("_distribution_id") != id(rows):
        total=sum(r[weight] for r in rows)
        if total<=0:
            raise ValueError("No probability mass")
        cumulative=0.0
        for row in rows:
            cumulative+=row[weight]/total
            row["_cdf"]=cumulative
            row["_distribution_id"]=id(rows)
    value=rng.random()
    lo,hi=0,len(rows)-1
    while lo<hi:
        mid=(lo+hi)//2
        if value<rows[mid]["_cdf"]:
            hi=mid
        else:
            lo=mid+1
    return rows[lo]


def rules(season, round_):
    if season < 2016:
        raise ValueError("Unsupported rule era")
    tries = (season >= 2021 and round_ >= 3) or (2019 <= season <= 2020 and round_ >= 5)
    mandatory_two = round_ >= (2 if season >= 2021 else 3)
    return tries, mandatory_two


def simulate(artifact, row, draws=50000, seed=7, signal=True, benchmark=False):
    if stamp(row["kickoff"]) <= stamp(artifact["trained_before"]):
        raise ValueError("Artifact must precede target kickoff")
    for side in ("home", "away"):
        if str(row[side+"_id"]) not in artifact["effective_drives"]:
            raise ValueError("Opponent has no connected observed history")
    rng = random.Random(seed)
    outcomes = {}
    means = {}
    adjustments = {"home": 0.0, "away": 0.0}
    signal_model = artifact.get("signal_model")
    if signal and signal_model:
        known=lambda market:{k:v for k,v in row.get("signals",{}).get(market,{}).items() if not row.get("signal_missing",{}).get(k,True)}
        margin = predict(signal_model["spread"], known("spread"))
        total = predict(signal_model["total"], known("total"))
        adjustments = {"home": (total+margin)/2, "away": (total-margin)/2}
    pace = {side: max(1, predict(artifact["models"]["pace"], {"team:"+str(row[side+"_id"]):1})) for side in ("home","away")}
    for side, opposite in (("home","away"),("away","home")):
        x = features(row[side+"_id"],row[opposite+"_id"],side=="home",row.get("neutral",False))
        x.update({key+"_eff":predict(artifact["models"][key],x) for key in artifact.get("efficiency_keys",["pass","rush"])})
        mean = predict(artifact["models"]["drive"], x)+adjustments[side]/pace[side]
        if benchmark:
            if "fpi_margin" not in artifact["models"]:
                raise ValueError("No eligible FPI distribution benchmark")
            fx=fpi_features(row)
            margin=predict(artifact["models"]["fpi_margin"],fx)
            total=predict(artifact["models"]["fpi_total"],fx)
            mean=(total+(margin if side=="home" else -margin))/2/pace[side]
        means[side] = mean
        outcomes[side] = tilt(artifact["outcomes"], mean)
    logs=[-.5/artifact["pace_variance"]*((p.count("home")-pace["home"])**2+(p.count("away")-pace["away"])**2) for p in artifact["patterns"]]
    largest=max(logs)
    pattern_weights=[{"pattern":p,"periods":artifact.get("pattern_periods",[])[index] if artifact.get("pattern_periods") else None,"probability":math.exp(value-largest)} for index,(p,value) in enumerate(zip(artifact["patterns"],logs))]
    score_counts = collections.Counter()
    # OT probabilities are learned only from eligible overtime observations, never defaults.
    ot = artifact["overtime"]
    dynamic_cache={}
    for _ in range(draws):
        points = {"home":0,"away":0}
        selected=pick(rng,pattern_weights)
        pattern=selected["pattern"]
        for drive_index, side in enumerate(pattern):
            opposite="away" if side=="home" else "home"
            late=selected["periods"][drive_index]==4 if selected["periods"] else drive_index>=.75*len(pattern)
            margin=points[side]-points[opposite]
            key=(side,margin if late else 0,late)
            if key not in dynamic_cache:
                correction=(max(0,margin)/7*artifact["models"]["drive"]["coefficients"].get("late_lead",0)+max(0,-margin)/7*artifact["models"]["drive"]["coefficients"].get("late_trail",0)) if late and not benchmark else 0
                dynamic_cache[key]=tilt(artifact["outcomes"],means[side]+correction)
            outcome = pick(rng, dynamic_cache[key])
            opposite = "away" if side == "home" else "home"
            points[side] += outcome["points"]
            points[opposite] += outcome["opponent_points"]
        round_ = 1
        while points["home"] == points["away"]:
            tries, two = rules(row["season"],round_)
            eligible = [o for o in ot if rules(o["season"],o["round"]) == (tries,two)]
            if not eligible:
                raise ValueError("No observed overtime outcomes for applicable rule state")
            first = "home" if round_ % 2 else "away"
            for side in (first, "away" if first=="home" else "home"):
                outcome = eligible[rng.randrange(len(eligible))]
                opposite = "away" if side == "home" else "home"
                points[side] += outcome["points"]
                points[opposite] += outcome["opponent_points"]
                if outcome["opponent_points"] and not outcome["points"]:
                    break # A defensive score ends the overtime game.
            round_ += 1
            if round_ > 100:
                raise ValueError("Degenerate overtime artifact")
        score_counts[(points["home"],points["away"])] += 1
    distribution=[{"home":h,"away":a,"probability":n/draws} for (h,a),n in sorted(score_counts.items())]
    if not benchmark and artifact.get("fpi_weight",0)>0:
        reference=simulate(artifact,row,draws,seed+101,signal=False,benchmark=True)
        distribution=mixture(distribution,reference,artifact["fpi_weight"])
    return temper(distribution, 1.0 if benchmark else artifact.get("calibration_temperature",1.0))


def mixture(local,reference,weight):
    if not 0<=weight<=1:
        raise ValueError("Invalid fitted blend")
    probabilities=collections.defaultdict(float)
    for distribution,share in ((local,1-weight),(reference,weight)):
        for r in distribution:
            probabilities[(r["home"],r["away"])]+=share*r["probability"]
    return [{"home":h,"away":a,"probability":p} for (h,a),p in sorted(probabilities.items()) if p>0]


def select_blend(model, rows):
    forecasts=[]
    for row in rows:
        try:
            forecasts.append((row,simulate(model,row,1000,seed=int(row["game_id"])),simulate(model,row,1000,seed=int(row["game_id"])+101,benchmark=True)))
        except ValueError:
            continue
    if len(forecasts)<20:
        return {"weight":0.,"status":"insufficient_common_fpi_validation","games":len(forecasts)}
    scores=[]
    for weight in (0.,.25,.5,.75,1.):
        score=sum(energy(mixture(local,reference,weight),row["home_score"],row["away_score"],pairs=1000) for row,local,reference in forecasts)/len(forecasts)
        scores.append((score,weight))
    value,weight=min(scores)
    return {"weight":weight,"status":"selected_on_common_validation_games","games":len(forecasts),"energy":value}


def temper(distribution, temperature):
    if not .5<=temperature<=2:
        raise ValueError("Invalid calibration temperature")
    weights=[r["probability"]**(1/temperature) for r in distribution]
    total=sum(weights)
    return [{**{k:v for k,v in r.items() if not k.startswith("_")},"probability":w/total} for r,w in zip(distribution,weights)]


def calibrate(model, rows):
    forecasts=[]
    for row in rows:
        try:
            d=simulate(model,row,2000,seed=int(row["game_id"]))
            forecasts.append((row,d))
        except ValueError:
            continue
    if len(forecasts)<50:
        return {"status":"insufficient_calibration_games","games":len(forecasts),"temperature":1.0}
    candidates=[]
    for temperature in (.7,.85,1.0,1.15,1.3):
        score=sum(energy(temper(d,temperature),r["home_score"],r["away_score"],pairs=1000) for r,d in forecasts)/len(forecasts)
        candidates.append((score,temperature))
    score,temperature=min(candidates)
    return {"status":"fitted_on_separate_calibration_weeks","games":len(forecasts),"temperature":temperature,"energy":score,
            "game_ids":[r["game_id"] for r,_ in forecasts]}


def summary(distribution):
    home = sum(r["home"]*r["probability"] for r in distribution)
    away = sum(r["away"]*r["probability"] for r in distribution)
    return {"home":home,"away":away,"margin":home-away,"total":home+away,
            "home_win":sum(r["probability"] for r in distribution if r["home"]>r["away"])}


def energy(distribution, home, away, seed=71, pairs=2000):
    """Energy score; independent-pair Monte Carlo for the forecast self-distance."""
    first = sum(r["probability"]*math.hypot(r["home"]-home,r["away"]-away) for r in distribution)
    rng = random.Random(seed)
    second = 0
    for _ in range(pairs):
        a,b = pick(rng,distribution),pick(rng,distribution)
        second += math.hypot(a["home"]-b["home"],a["away"]-b["away"])
    return first-.5*second/pairs


def crps(distribution, observed, field):
    counts=collections.defaultdict(float)
    for r in distribution:
        value=r["home"]-r["away"] if field=="margin" else r["home"]+r["away"]
        counts[value]+=r["probability"]
    values=sorted(counts)
    return sum(counts[x]*abs(x-observed) for x in values)-.5*sum(counts[x]*counts[y]*abs(x-y) for x in values for y in values)


def priced_outcomes(distribution, row):
    results=[]
    for quote in row.get("quotes",[]):
        market,side=quote["market"],quote["side"]
        line=quote.get("line")
        price=quote.get("price")
        if market not in ("spreads","totals","h2h","team_totals") or not isinstance(price,(int,float)) or abs(price)<100:
            continue
        if market!="h2h" and not isinstance(line,(int,float)):
            continue
        if market=="team_totals" and quote.get("participant") not in ("home","away"):
            continue
        if side not in (("home","away") if market in ("h2h","spreads") else ("over","under")):
            continue
        def value(home,away):
            if market in ("spreads","h2h"):
                return (home-away)*(1 if side=="home" else -1)+(line if market=="spreads" else 0)
            score=home+away if market=="totals" else (home if quote["participant"]=="home" else away)
            return (score-line)*(1 if side=="over" else -1)
        probabilities={"win":0.,"push":0.,"loss":0.}
        label=lambda v:"win" if v>0 else ("loss" if v<0 else "push")
        for score in distribution:
            probabilities[label(value(score["home"],score["away"]))]+=score["probability"]
        actual=label(value(row["home_score"],row["away_score"]))
        payout=price/100 if price>0 else 100/abs(price)
        results.append({**quote,**probabilities,"actual":actual,"ev":probabilities["win"]*payout-probabilities["loss"],
            "profit_units":payout if actual=="win" else (-1 if actual=="loss" else 0),
            "multiclass_brier":sum((p-int(k==actual))**2 for k,p in probabilities.items())})
    return results


def evaluate(model, rows, draws=2000, benchmark=False):
    scores, excluded = [], collections.Counter()
    for row in rows:
        try:
            d=simulate(model,row,draws,seed=int(row["game_id"]),benchmark=benchmark)
        except ValueError as error:
            excluded[str(error)] += 1
            continue
        s=summary(d)
        p=max(1/(2*draws),min(1-1/(2*draws),s["home_win"]))
        actual=int(row["home_score"]>row["away_score"])
        scores.append({"game_id":row["game_id"],"week":week(row),"predicted":s, "actual_margin":row["home_score"]-row["away_score"], "actual_total":row["home_score"]+row["away_score"], "home_win":actual,
            "priced_markets":priced_outcomes(d,row),
            "score_squared_error":((s['home']-row['home_score'])**2+(s['away']-row['away_score'])**2)/2,
            "score_mae":(abs(s['home']-row['home_score'])+abs(s['away']-row['away_score']))/2,
            "score_bias":((s['home']-row['home_score'])+(s['away']-row['away_score']))/2,
            "margin_bias":s['margin']-(row['home_score']-row['away_score']),
            "total_bias":s['total']-(row['home_score']+row['away_score']),
            "cohorts":['weeks_0_3' if row.get('week',99)<=3 else 'later_weeks',
                'neutral' if row.get('neutral') else 'home_away',
                'fbs_fcs' if 'fcs' in [str(x).lower() for x in row.get('subdivisions',{}).values()] else 'other_or_unknown_subdivision',
                spread_bucket(row)],
            "energy":energy(d,row["home_score"],row["away_score"]),
            "margin_mae":abs(s["margin"]-(row["home_score"]-row["away_score"])),
            "total_mae":abs(s["total"]-(row["home_score"]+row["away_score"])),
            "margin_crps":crps(d,row["home_score"]-row["away_score"],"margin"),
            "total_crps":crps(d,row["home_score"]+row["away_score"],"total"),
            "brier":(p-actual)**2,"log_loss":-math.log(p if actual else 1-p)})
    def aggregate(group):
        if not group:
            return {'games':0}
        fields=['energy','score_mae','score_bias','margin_bias','total_bias','margin_mae','total_mae','margin_crps','total_crps','brier','log_loss']
        return {'games':len(group),'score_rmse':math.sqrt(sum(g['score_squared_error'] for g in group)/len(group)),
            **{key:sum(g[key] for g in group)/len(group) for key in fields},'reliability':reliability(group),
            'status':'descriptive_only' if len(group)<50 else 'holdout_diagnostic'}
    cohorts={name:aggregate([g for g in scores if name in g['cohorts']]) for name in sorted({name for g in scores for name in g['cohorts']})}
    return {"games":scores,"excluded":dict(excluded),"summary":aggregate(scores),"cohorts":cohorts,
        "mean_energy":sum(r["energy"] for r in scores)/len(scores) if scores else None}


def spread_bucket(row):
    lines=[abs(q['line']) for q in row.get('quotes',[]) if q['market']=='spreads' and isinstance(q.get('line'),(int,float))]
    if not lines:
        return 'spread_unpriced'
    # Multiple observed books remain distinct in pricing; median is only a cohort label.
    lines.sort()
    value=lines[len(lines)//2]
    return 'spread_'+('<20' if value<20 else ('20-28' if value<28 else ('28-35' if value<35 else '35+')))


def compare(challenger, benchmark, resamples=2000):
    base={r["game_id"]:r for r in benchmark["games"]}
    groups=collections.defaultdict(list)
    for r in challenger["games"]:
        if r["game_id"] in base:
            groups[r["week"]].append(base[r["game_id"]]["energy"]-r["energy"])
    if len(groups)<4:
        return {"status":"insufficient_paired_weeks","paired_weeks":len(groups),"promotion_allowed":False}
    rng=random.Random(618)
    keys=list(groups)
    lifts=[]
    for _ in range(resamples):
        values=[value for _ in keys for value in groups[rng.choice(keys)]]
        lifts.append(sum(values)/len(values))
    lifts.sort()
    return {"status":"evaluated","paired_weeks":len(keys),"paired_games":sum(map(len,groups.values())),
            "energy_improvement_interval":[lifts[int(.025*resamples)],lifts[int(.975*resamples)]],
            "promotion_allowed":False,"remaining":["released_point_baseline_tolerance","prospective_shadow","market_calibration"]}


def reliability(rows, minimum=20):
    bins=[]
    for i in range(10):
        group=[r for r in rows if min(9,int(r["predicted"]["home_win"]*10))==i]
        n=len(group)
        if not n:
            continue
        observed=sum(r["home_win"] for r in group)/n
        z=1.96
        center=(observed+z*z/(2*n))/(1+z*z/n)
        radius=z*math.sqrt(observed*(1-observed)/n+z*z/(4*n*n))/(1+z*z/n)
        bins.append({"count":n,"predicted":sum(r["predicted"]["home_win"] for r in group)/n,
                     "observed":observed,"interval":[center-radius,center+radius],"supported":n>=minimum})
    return bins


def fit_signal_residuals(rows, cutoff, penalty, decay, families, retrospective=False, fpi_weight=0.0):
    eligible=prior(rows,cutoff,retrospective)
    by_week=sorted({week(r) for r in eligible})
    residuals=[]
    # Out-of-fold baseline residuals: each week is predicted without itself or later games.
    for wk in by_week:
        group=[r for r in eligible if week(r)==wk and r.get("snapshot_hash")]
        if not group:
            continue
        try:
            baseline=fit(eligible,wk+"T00:00:00+00:00",penalty,decay,retrospective)
        except ValueError:
            continue
        baseline["fpi_weight"]=fpi_weight
        predictions=evaluate(baseline,group,draws=1000)["games"]
        lookup={r["game_id"]:r for r in group}
        for result in predictions:
            row=lookup[result["game_id"]]
            residuals.append({"row":row,"week":wk,
                "spread":result["actual_margin"]-result["predicted"]["margin"],
                "total":result["actual_total"]-result["predicted"]["total"]})
    weeks=sorted({r["week"] for r in residuals})
    if len(weeks)<4 or len(residuals)<100:
        return None, {"status":"insufficient_baseline_matched_residuals","games":len(residuals)}
    boundary=weeks[int(len(weeks)*.75)]
    train_rows=[r for r in residuals if r["week"]<boundary]
    validation=[r for r in residuals if r["week"]>=boundary]
    models,diagnostics={},{}
    for market in ("spread","total"):
        records=[]
        counts=collections.Counter()
        missing=collections.Counter()
        for r in train_rows:
            x={k:v for k,v in r["row"]["signals"][market].items() if not r["row"].get("signal_missing",{}).get(k,True)}
            counts.update(k for k,v in x.items() if v)
            missing.update(k for k,v in r["row"].get("signal_missing",{}).items() if v)
            records.append((x,r[market],1.0))
        supported={k for k,n in counts.items() if n>=20}
        records=[({k:v for k,v in x.items() if k in supported},y,w) for x,y,w in records]
        scored=[]
        for strength in (10.,30.,100.,300.):
            model=ridge(records,strength)
            error=sum((r[market]-predict(model,{k:v for k,v in r["row"]["signals"][market].items() if not r["row"].get("signal_missing",{}).get(k,True)}))**2 for r in validation)/len(validation)
            scored.append((error,strength,model))
        error,strength,model=min(scored,key=lambda v:v[0])
        baseline=sum(r[market]**2 for r in validation)/len(validation)
        ablations={}
        for family in set(families.values()):
            # Refit with the family removed; zeroing correlated coefficients alone is not an ablation.
            reduced=ridge([({k:v for k,v in x.items() if families.get(k)!=family},y,w) for x,y,w in records],strength)
            reduced_error=sum((r[market]-predict(reduced,{k:v for k,v in r["row"]["signals"][market].items() if not r["row"].get("signal_missing",{}).get(k,True)}))**2 for r in validation)/len(validation)
            ablations[family]=reduced_error-error
        # Week-block bootstrap describes fitted coefficient uncertainty, not causality.
        blocks=collections.defaultdict(list)
        for record,source in zip(records,train_rows):
            blocks[source['week']].append(record)
        block_values=list(blocks.values())
        rng=random.Random(790)
        draws=collections.defaultdict(list)
        for _ in range(50):
            sample=[record for _ in block_values for record in rng.choice(block_values)]
            refit=ridge(sample,strength)
            for key in supported:
                draws[key].append(refit['coefficients'].get(key,0.))
        details={}
        for key in families:
            values=sorted(draws.get(key,[]))
            contribution=model['coefficients'].get(key,0.) if error<baseline else 0.
            details[key]={'active_training_games':counts[key],'missing_training_games':missing[key],
                'coefficient':contribution,'bootstrap_interval':[values[1],values[48]] if values else None,
                'bootstrap_sign_agreement':sum((v>0)==(contribution>0) for v in values)/len(values) if values and contribution else None,
                'status':'applied_candidate' if key in supported and error<baseline else 'unsupported_or_no_incremental_gain'}
        models[market]=model if error<baseline else {"coefficients":{},"observations":len(records)}
        diagnostics[market]={"training_games":len(records),"validation_games":len(validation),"activation_counts":dict(counts),
            "missing_counts":dict(missing),"penalty":strength,"baseline_mse":baseline,"adjusted_mse":error,
            "signals":details,"uncertainty_method":"50 kickoff-week block refits; descriptive after model selection", "family_ablation_mse_increase":ablations,"status":"candidate_incremental_improvement" if error<baseline else "no_improvement"}
    return models,diagnostics


def train(manifest_path, output, retrospective=False):
    manifest=json.loads(Path(manifest_path).read_text())
    content=Path(manifest["data_path"]).read_bytes()
    if hashlib.sha256(content).hexdigest()!=manifest["sha256"]:
        raise ValueError("Dataset hash mismatch")
    rows=[json.loads(line) for line in content.splitlines()]
    rows.sort(key=lambda r: stamp(r["kickoff"]))
    if len({r["game_id"] for r in rows})!=len(rows):
        raise ValueError("Duplicate games in export")
    weeks=sorted({week(r) for r in rows})
    if len(weeks)<12:
        raise ValueError("Need at least 12 independent kickoff weeks for train/validation/test")
    seasons=sorted({r["season"] for r in rows})
    test_season=manifest.get('test_season')
    if len(seasons)>1 and test_season is None:
        raise ValueError('Multi-season exports require an explicit complete test_season')
    if test_season is not None and (test_season not in seasons or stamp(manifest['as_of']) < dt.datetime(test_season+1,3,1,tzinfo=dt.timezone.utc)):
        raise ValueError('Test season must be present and complete before as_of')
    test_week=min(week(r) for r in rows if r['season']==test_season) if test_season is not None else weeks[int(len(weeks)*.85)]
    earlier=[wk for wk in weeks if wk<test_week]
    if len(earlier)<8:
        raise ValueError("Need eight pre-test weeks for separate training, selection and calibration")
    val_week=earlier[int(len(earlier)*.6)]
    calibration_week=earlier[int(len(earlier)*.8)]
    start=lambda value:value+"T00:00:00+00:00"
    validation=[r for r in rows if val_week<=week(r)<calibration_week]
    test=[r for r in rows if (r['season']==test_season if test_season is not None else week(r)>=test_week)]
    candidates=[]
    for penalty in (10.,30.,100.):
        for decay in (.5,.8,1.):
            model=fit(rows,start(val_week),penalty,decay,retrospective)
            report=evaluate(model,validation)
            if report["mean_energy"] is not None:
                candidates.append((report["mean_energy"],penalty,decay))
    if not candidates:
        raise ValueError("No evaluable validation games; inspect history and overtime coverage")
    _,penalty,decay=min(candidates)
    selection_model=fit(rows,start(val_week),penalty,decay,retrospective)
    blend=select_blend(selection_model,validation)
    model=fit(rows,start(calibration_week),penalty,decay,retrospective)
    model["fpi_weight"]=blend["weight"]
    model["fpi_blend_selection"]=blend
    signal_model,signal_report=fit_signal_residuals(rows,start(calibration_week),penalty,decay,manifest.get("signal_families",{}),retrospective,model.get("fpi_weight",0.0))
    model["signal_model"]=signal_model
    model["signal_diagnostics"]=signal_report
    calibration=calibrate(model,[r for r in rows if calibration_week<=week(r)<test_week])
    model["calibration"]=calibration
    model["calibration_temperature"]=calibration["temperature"]
    model["calibration_status"]=calibration["status"]
    model["base_trained_before"]=model["trained_before"]
    model["trained_before"]=start(test_week)
    report=evaluate(model,test)
    model["reliability"]=reliability(report["games"])
    benchmark=evaluate(model,test,benchmark=True)
    model["benchmark_holdout"]=benchmark
    model["promotion_report"]=compare(report,benchmark)
    # Freeze the evaluation result before the deployment refit. The refit uses only
    # records actually available at export time; promotion additionally requires live
    # shadow evidence from this exact registered artifact.
    as_of=manifest.get("as_of")
    if not as_of:
        raise ValueError("Export manifest requires an explicit as_of timestamp")
    deployment=fit(rows,as_of,penalty,decay,retrospective)
    deployment.update({"fpi_weight":model.get("fpi_weight",0.),"fpi_blend_selection":blend,
        "signal_model":signal_model,"signal_diagnostics":signal_report,"calibration":calibration,
        "calibration_temperature":calibration["temperature"],"calibration_status":calibration["status"],
        "reliability":model["reliability"],"benchmark_holdout":benchmark,"promotion_report":model["promotion_report"],
        "dataset_hash":manifest["sha256"],"split":{"test_season":test_season,"validation_week":val_week,"calibration_week":calibration_week,"test_week":test_week},
        "evaluation_model_trained_before":model["trained_before"],"refit_policy":"fixed_selected_hyperparameters_after_frozen_holdout",
        "holdout":report,"status":"challenger","promotion_allowed":False})
    Path(output).write_text(json.dumps(deployment,sort_keys=True,allow_nan=False))
    return {"artifact":output,"sha256":hashlib.sha256(Path(output).read_bytes()).hexdigest(),
            "test_games":len(report["games"]),"excluded":report["excluded"],"status":"challenger"}


if __name__ == "__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--manifest",required=True)
    parser.add_argument("--output",required=True)
    parser.add_argument("--retrospective",action="store_true")
    args=parser.parse_args()
    try:
        if Path(args.output).exists():
            raise ValueError("Refusing to overwrite an immutable artifact")
        print(json.dumps(train(args.manifest,args.output,args.retrospective)))
    except (ValueError, KeyError, OSError) as error:
        parser.exit(2,str(error)+"\n")
