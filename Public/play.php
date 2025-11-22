<?php
require_once __DIR__.'/../src/Repositories.php';
require_once __DIR__.'/../src/Board.php';
require_once __DIR__.'/../src/Scorer.php';
require_once __DIR__.'/../src/MoveParser.php';

$game_id = (int)($_GET['game_id'] ?? 0);
$game = GameRepo::get($game_id);
if (!$game) { http_response_code(404); echo 'Brak gry.'; exit; }

$playersById = [];
foreach (PlayerRepo::all() as $p) $playersById[$p['id']] = $p['nick'];

$moves = MoveRepo::byGame($game_id);
$lastMove = count($moves) ? $moves[count($moves)-1] : null;
$board = new Board();
$scorer = new Scorer($board);

$scores = [$game['player1_id']=>0, $game['player2_id']=>0];
$err=null; $msg=null;

$nextPlayer = $game['player1_id'];
if (count($moves)>0) {
    // Rebuild board and cumulative scores
    foreach ($moves as $m) {
        if ($m['type']==='PLAY') {
            try {
                $scorer->placeAndScore($m['position'],$m['word']);
            } catch (Throwable $e) {
                $err = 'Błąd historycznego ruchu #'.$m['move_no'].': '.$e->getMessage();
                break;
            }
        }
        $scores[$m['player_id']] = $m['cum_score'];
        $nextPlayer = ($m['player_id']===$game['player1_id'])
            ? $game['player2_id']
            : $game['player1_id'];
    }
}

// Obsługa nowego ruchu
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['raw'])) {
    $player_id = (int)($_POST['player_id'] ?? 0);
    try {
        $parser = new MoveParser();
        $pm = $parser->parse(trim($_POST['raw']));

        $score = 0;
        if ($pm->type === 'PLAY') {
            $placement = $scorer->placeAndScore($pm->pos, $pm->word);
            $score = $placement->score;
        } elseif ($pm->type === 'EXCHANGE') {
            $score = 0;
        } elseif ($pm->type === 'PASS') {
            $score = 0;
        }

        $moveNo = count($moves)+1;
        $cum = $scores[$player_id] + $score;

        MoveRepo::add([
            'game_id'=>$game_id,
            'move_no'=>$moveNo,
            'player_id'=>$player_id,
            'raw_input'=>trim($_POST['raw']),
            'type'=>$pm->type,
            'position'=>$pm->pos ?? null,
            'word'=>$pm->word ?? null,
            'rack'=>$pm->rack ?? null,
            'score'=>$score,
            'cum_score'=>$cum,
        ]);
        header('Location: play.php?game_id='.$game_id);
        exit;
    } catch (Throwable $e) {
        $err = 'Błąd: '.$e->getMessage();
    }
}

// Helpers for rendering
function letterClass(Board $b,int $r,int $c): string {
    if ($b->cells[$r][$c]['letter']) return 'tile';
    $ps = $b->W[$r][$c];
    $pl = $b->L[$r][$c];
    if ($ps===3) return 'ps3';
    if ($ps===2) return 'ps2';
    if ($pl===3) return 'pl3';
    if ($pl===2) return 'pl2';
    return '';
}

?>
<!doctype html><html lang="pl"><head>
<meta charset="utf-8"><title>Gra #<?=$game_id?></title>
<link rel="stylesheet" href="../assets/style.css">
</head><body><div class="container">
<h1>Gra #<?=$game_id?> — <?=htmlspecialchars($playersById[$game['player1_id']] ?? '')?> vs <?=htmlspecialchars($playersById[$game['player2_id']] ?? '')?></h1>
<?php if($err): ?><div class="card error"><?=$err?></div><?php endif; ?>
<div class="grid">
<div class="card">
<h2>Ruchy</h2>
<table><tr><th>#</th><th>Gracz</th><th>Zapis</th><th>+pkt</th><th>Σ</th></tr>
<?php foreach($moves as $m): ?>
<tr>
<td><?=$m['move_no']?></td>
<td><?=htmlspecialchars($m['nick'])?></td>
<td>
    <?=htmlspecialchars($m['raw_input'])?>
    <?php if($m['type']==='EXCHANGE' && $m['rack']): ?>
        <span class="small">(wymiana: <?=htmlspecialchars($m['rack'])?>)</span>
    <?php endif; ?>
</td>
<td><?=$m['score']?></td>
<td><?=$m['cum_score']?></td>
</tr>
<?php endforeach; ?>
</table>

<?php if ($lastMove && $lastMove['type'] === 'PLAY'): ?>
<h3>Kwestionowanie ostatniego ruchu</h3>
<p>Ostatni ruch:
    <strong><?=htmlspecialchars($lastMove['raw_input'])?></strong>
    (gracz: <?=htmlspecialchars($playersById[$lastMove['player_id']] ?? '')?>)
</p>
<form method="post" action="challenge.php" style="display:inline">
    <input type="hidden" name="game_id" value="<?=$game_id?>">
    <button class="btn">Kwestionuj</button>
</form>
<a class="btn" href="play.php?game_id=<?=htmlspecialchars((string)$game_id)?>">Następny ruch</a>
<?php endif; ?>

<h3>Dodaj ruch</h3>
<form method="post">
<label>Gracz wykonujący ruch</label>
<select name="player_id">
<option value="<?=$game['player1_id']?>"><?=htmlspecialchars($playersById[$game['player1_id']])?></option>
<option value="<?=$game['player2_id']?>"><?=htmlspecialchars($playersById[$game['player2_id']])?></option>
</select>
<label>Zapis ruchu (np. "WIJĘKRA 8F WIJĘ" lub "7G BAGNO" lub "K4 KAR(O)" lub "PASS" lub "EXCHANGE")</label>
<input name="raw" required>
<button class="btn" style="margin-top:8px">Zatwierdź</button>
</form>
</div>

<div class="card">
<h2>Plansza</h2>
<div class="board">
<?php for($r=0;$r<15;$r++): for($c=0;$c<15;$c++): ?>
<div class="cell <?=letterClass($board,$r,$c)?>">
<?php if($board->cells[$r][$c]['letter']): ?>
<?=htmlspecialchars($board->cells[$r][$c]['letter'])?>
<?php else: ?>&nbsp;<?php endif; ?>
</div>
<?php endfor; endfor; ?>
</div>
<p class="small">Niebieskie pola — premie literowe, czerwone — słowne.</p>
</div>
</div>

<p><a class="btn" href="new_game.php">Powrót</a></p>
</div></body></html>
