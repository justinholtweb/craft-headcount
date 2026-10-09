<?php
/**
 * GraphQL checks, run against the shared plugin-testing harness:
 *
 *     /tmp/hxl.sh php /var/www/craft-headcount/tests/harness/graphql.php
 *
 * Seeds two plans (one disabled), two members with a subscription each, and a gated and a
 * disabled entry; runs real queries through `Gql::executeQuery()` against schemas with and
 * without each Headcount component (one PHP process per schema, see _gql.php); then deletes
 * everything it made. Plain PHP rather than Codeception: never run a Craft-backed
 * Codeception suite in the harness, it drops every table.
 */

use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\helpers\Payload;
use justinholtweb\headcount\models\AccessRule;
use justinholtweb\headcount\models\Plan;

require __DIR__ . '/_boot.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $label" . (!$ok && $detail !== '' ? "\n      $detail" : '') . "\n";
}

/**
 * @return array<int, array{result: array, cachingAfter: bool}>
 */
function gql(array $scope, array $runs, bool $caching = false): array
{
    $job = base64_encode(json_encode(['scope' => $scope, 'runs' => $runs, 'caching' => $caching]));
    $output = shell_exec('php ' . escapeshellarg(__DIR__ . '/_gql.php') . ' ' . escapeshellarg($job) . ' 2>&1');
    $decoded = json_decode((string)$output, true);

    if (!is_array($decoded)) {
        echo "      _gql.php said: " . substr((string)$output, 0, 2000) . "\n";
        return [];
    }

    return $decoded;
}

function data(array $runs, int $i = 0): array
{
    return $runs[$i]['result']['data'] ?? [];
}

$plugin = Headcount::getInstance();
$elements = Craft::$app->getElements();
$suffix = substr(md5((string)microtime(true)), 0, 6);
$made = ['plans' => [], 'rules' => [], 'elements' => []];

try {
    // -- Seed ---------------------------------------------------------------------------

    $pro = new Plan([
        'name' => "GQL Pro $suffix",
        'handle' => "gqlPro$suffix",
        'price' => 12.5,
        'currency' => 'USD',
        'features' => ['Archive', 'Forum'],
        'enabled' => true,
    ]);
    $hidden = new Plan([
        'name' => "GQL Hidden $suffix",
        'handle' => "gqlHidden$suffix",
        'price' => 99,
        'enabled' => false,
    ]);
    foreach ([$pro, $hidden] as $plan) {
        if (!$plugin->plans->savePlan($plan)) {
            throw new RuntimeException('Plan save failed: ' . json_encode($plan->getErrors()));
        }
        $made['plans'][] = $plan->id;
    }

    $members = [];
    foreach (['a', 'b'] as $who) {
        $user = new User([
            'username' => "hcgql-$who-$suffix",
            'email' => "hcgql-$who-$suffix@example.test",
        ]);
        if (!$elements->saveElement($user, false)) {
            throw new RuntimeException('User save failed');
        }
        $made['elements'][] = $user;
        $members[$who] = $user;
    }

    $subs = [];
    foreach (['a' => 'active', 'b' => 'active'] as $who => $status) {
        $sub = new Subscription();
        $sub->userId = $members[$who]->id;
        $sub->planId = $pro->id;
        $sub->gateway = 'manual';
        $sub->gatewaySubscriptionId = "sub_secret_$who$suffix";
        $sub->gatewayCustomerId = "cus_secret_$who$suffix";
        $sub->status = $status;
        $sub->amount = 12.5;
        $sub->startDate = new DateTime('-3 days');
        if (!$elements->saveElement($sub)) {
            throw new RuntimeException('Subscription save failed: ' . json_encode($sub->getErrors()));
        }
        $made['elements'][] = $sub;
        $subs[$who] = $sub;
    }

    $section = Craft::$app->getEntries()->getSectionByHandle('news');
    $entryType = $section?->getEntryTypes()[0] ?? null;
    if (!$section || !$entryType) {
        throw new RuntimeException('The harness has no `news` section to put entries in');
    }
    $otherSection = null;
    foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
        if ($candidate->type === 'channel' && $candidate->id !== $section->id) {
            $otherSection = $candidate;
            break;
        }
    }

    $makeEntry = function(string $title, bool $enabled, $inSection) use ($elements, &$made) {
        $entry = new Entry();
        $entry->sectionId = $inSection->id;
        $entry->typeId = $inSection->getEntryTypes()[0]->id;
        $entry->title = $title;
        $entry->enabled = $enabled;
        if (!$elements->saveElement($entry)) {
            throw new RuntimeException('Entry save failed: ' . json_encode($entry->getErrors()));
        }
        $made['elements'][] = $entry;
        return $entry;
    };

    $gated = $makeEntry("GQL gated $suffix", true, $section);
    $open = $makeEntry("GQL open $suffix", true, $section);
    $disabled = $makeEntry("GQL disabled $suffix", false, $section);
    $elsewhere = $otherSection ? $makeEntry("GQL elsewhere $suffix", true, $otherSection) : null;

    // Rules target single entries, never a whole section: other builders share this harness.
    foreach ([$gated, $disabled, $elsewhere] as $target) {
        if (!$target) {
            continue;
        }
        $rule = new AccessRule([
            'name' => "GQL rule $suffix #$target->id",
            'elementType' => Entry::class,
            'type' => 'element',
            'targetId' => $target->id,
            'planIds' => [$pro->id, $hidden->id],
            'behavior' => 'paywall',
            'teaserLength' => 140,
            'enabled' => true,
        ]);
        if (!$plugin->gating->saveRule($rule)) {
            throw new RuntimeException('Rule save failed: ' . json_encode($rule->getErrors()));
        }
        $made['rules'][] = $rule->id;
    }

    $siteUid = Craft::$app->getSites()->getPrimarySite()->uid;
    $sites = ["sites.$siteUid:read"];
    $newsScope = ["sections.$section->uid:read"];

    $planQuery = "{ headcountPlans { id name handle description price currency billingInterval billingIntervalCount termType seasonStart seasonEnd currentPrice trialDays features } }";
    $memberQuery = '{ headcountMembership { userId isMember activePlanHandles isSubscribed any: isSubscribed sub: isSubscribed(plan: "gqlPro' . $suffix . '") nope: isSubscribed(plan: "nope") subscriptions { id planHandle status amount gateway } } }';
    $accessQuery = fn(int $id) => "{ headcountAccess(entryId: $id) { allowed restricted reason behavior teaserLength requiredPlans unlocksInDays } }";

    // -- No Headcount component ---------------------------------------------------------

    $none = gql($sites, [
        ['userId' => $members['a']->id, 'query' => '{ headcountPlans { handle } }'],
        ['userId' => $members['a']->id, 'query' => '{ headcountMembership { isMember } }'],
        ['userId' => $members['a']->id, 'query' => '{ headcountAccess(entryId: ' . $gated->id . ') { allowed } }'],
    ]);
    check('no component: headcountPlans is not in the schema', count($none) === 3 && !array_key_exists('headcountPlans', data($none, 0)), json_encode($none[0] ?? null));
    check('no component: headcountMembership is not in the schema', !array_key_exists('headcountMembership', data($none, 1)), json_encode($none[1] ?? null));
    check('no component: headcountAccess is not in the schema', !array_key_exists('headcountAccess', data($none, 2)), json_encode($none[2] ?? null));

    // -- Plans only ---------------------------------------------------------------------

    $plansOnly = gql(array_merge($sites, ['headcount.plans:read']), [
        ['userId' => null, 'query' => $planQuery],
        ['userId' => null, 'query' => '{ hidden: headcountPlan(handle: "gqlHidden' . $suffix . '") { handle } pro: headcountPlan(handle: "gqlPro' . $suffix . '") { handle features } }'],
        ['userId' => $members['a']->id, 'query' => '{ headcountMembership { isMember } }'],
        ['userId' => null, 'query' => '{ headcountPlans(handle: ["gqlPro' . $suffix . '", "gqlHidden' . $suffix . '"]) { handle } }'],
    ]);
    $listed = array_column(data($plansOnly, 0)['headcountPlans'] ?? [], null, 'handle');
    check('plans: an enabled plan is listed', isset($listed["gqlPro$suffix"]), json_encode($plansOnly[0] ?? null));
    check('plans: a disabled plan is not listed', !isset($listed["gqlHidden$suffix"]));

    // Same payload as `GET /actions/headcount/api/plans` (both come from Payload::plan()).
    $rest = json_decode(json_encode(Payload::plan($plugin->plans->getPlanById($pro->id))), true);
    $viaGql = $listed["gqlPro$suffix"] ?? [];
    $restKeys = array_keys($rest);
    sort($restKeys);
    $gqlKeys = array_keys($viaGql);
    sort($gqlKeys);
    check('plans: GraphQL fields are exactly the REST payload keys', $restKeys === $gqlKeys, json_encode([$restKeys, $gqlKeys]));
    $same = true;
    foreach ($rest as $key => $value) {
        if ((is_numeric($value) ? (float)$value : $value) !== (is_numeric($viaGql[$key] ?? null) ? (float)$viaGql[$key] : ($viaGql[$key] ?? null))) {
            $same = false;
            echo "      $key: REST " . json_encode($value) . ' vs GraphQL ' . json_encode($viaGql[$key] ?? null) . "\n";
        }
    }
    check('plans: GraphQL values match the REST payload', $same);
    check('plans: headcountPlan returns null for a disabled plan', array_key_exists('hidden', data($plansOnly, 1)) && data($plansOnly, 1)['hidden'] === null, json_encode($plansOnly[1] ?? null));
    check('plans: headcountPlan returns an enabled plan', (data($plansOnly, 1)['pro']['features'] ?? null) === ['Archive', 'Forum']);
    check('plans: plans-only schema has no headcountMembership', !array_key_exists('headcountMembership', data($plansOnly, 2)), json_encode($plansOnly[2] ?? null));
    check('plans: handle filter still hides the disabled plan', array_column(data($plansOnly, 3)['headcountPlans'] ?? [], 'handle') === ["gqlPro$suffix"], json_encode($plansOnly[3] ?? null));

    // -- Membership + the news section ------------------------------------------------

    $memberScope = array_merge($sites, $newsScope, ['headcount.membership:read']);
    $runs = gql($memberScope, [
        ['userId' => null, 'query' => $memberQuery],
        ['userId' => $members['a']->id, 'query' => $memberQuery],
        ['userId' => null, 'query' => $accessQuery($gated->id)],
        ['userId' => $members['a']->id, 'query' => $accessQuery($gated->id)],
        ['userId' => $members['a']->id, 'query' => $accessQuery($open->id)],
        ['userId' => $members['a']->id, 'query' => $accessQuery($disabled->id)],
        ['userId' => $members['a']->id, 'query' => $accessQuery($elsewhere?->id ?? 999999999)],
        ['userId' => $members['a']->id, 'query' => '{ headcountMembership { subscriptions { id gatewaySubscriptionId } } }'],
        ['userId' => $members['a']->id, 'query' => '{ headcountPlans { handle } }'],
    ]);

    $guest = data($runs, 0)['headcountMembership'] ?? null;
    check('membership: a guest gets an empty membership', $guest !== null && $guest['userId'] === null && $guest['isMember'] === false && $guest['subscriptions'] === [] && $guest['any'] === false, json_encode($runs[0] ?? null));

    $mine = data($runs, 1)['headcountMembership'] ?? null;
    check('membership: the session user is a member', ($mine['isMember'] ?? null) === true && ($mine['userId'] ?? null) === $members['a']->id, json_encode($runs[1] ?? null));
    check('membership: active plan handles', ($mine['activePlanHandles'] ?? null) === ["gqlPro$suffix"]);
    check('membership: isSubscribed with and without a plan', ($mine['any'] ?? null) === true && ($mine['sub'] ?? null) === true && ($mine['nope'] ?? null) === false);
    $ids = array_column($mine['subscriptions'] ?? [], 'id');
    check('membership: lists only the session user’s own subscription', $ids === [$subs['a']->id], json_encode($ids) . ' other member: ' . $subs['b']->id);
    // Craft drops a field the schema lacks rather than erroring, so assert on absence.
    check('membership: gateway IDs are not in the schema', !array_key_exists('gatewaySubscriptionId', data($runs, 7)['headcountMembership']['subscriptions'][0] ?? ['gatewaySubscriptionId' => 1]) && !str_contains(json_encode($runs[7]), '_secret_'), json_encode($runs[7] ?? null));
    check('membership: membership-only schema has no headcountPlans', !array_key_exists('headcountPlans', data($runs, 8)), json_encode($runs[8] ?? null));

    $guestGate = data($runs, 2)['headcountAccess'] ?? null;
    check('access: a guest is refused a gated entry', ($guestGate['allowed'] ?? null) === false && $guestGate['restricted'] === true && $guestGate['reason'] === 'subscription_required' && $guestGate['behavior'] === 'paywall' && $guestGate['teaserLength'] === 140, json_encode($runs[2] ?? null));
    check('access: requiredPlans lists only enabled plans', ($guestGate['requiredPlans'] ?? null) === ["gqlPro$suffix"], json_encode($guestGate));
    $memberGate = data($runs, 3)['headcountAccess'] ?? null;
    check('access: the member may see the gated entry', ($memberGate['allowed'] ?? null) === true && $memberGate['restricted'] === true && $memberGate['reason'] === null && $memberGate['behavior'] === null, json_encode($runs[3] ?? null));
    $openGate = data($runs, 4)['headcountAccess'] ?? null;
    check('access: an entry with no rule is unrestricted', ($openGate['allowed'] ?? null) === true && $openGate['restricted'] === false && $openGate['requiredPlans'] === [], json_encode($runs[4] ?? null));
    check('access: a disabled entry answers null', array_key_exists('headcountAccess', data($runs, 5)) && data($runs, 5)['headcountAccess'] === null, json_encode($runs[5] ?? null));
    check('access: an entry in a section the schema can’t read answers null', array_key_exists('headcountAccess', data($runs, 6)) && data($runs, 6)['headcountAccess'] === null, json_encode($runs[6] ?? null));

    // -- Membership without any section -------------------------------------------------

    $noSections = gql(array_merge($sites, ['headcount.membership:read']), [
        ['userId' => $members['a']->id, 'query' => $accessQuery($gated->id)],
    ]);
    check('access: no readable sections means null', array_key_exists('headcountAccess', data($noSections)) && data($noSections)['headcountAccess'] === null, json_encode($noSections[0] ?? null));

    // -- Caching ------------------------------------------------------------------------

    $cacheQuery = '{ headcountMembership { userId subscriptions { id } } }';
    $cached = gql($memberScope, [
        ['userId' => $members['a']->id, 'query' => $cacheQuery],
        ['userId' => $members['b']->id, 'query' => $cacheQuery],
        ['userId' => null, 'query' => $cacheQuery],
    ], true);
    $seen = array_map(fn($run) => array_key_exists('userId', $run['result']['data']['headcountMembership'] ?? []) ? $run['result']['data']['headcountMembership']['userId'] : 'missing', $cached);
    check('cache: with GraphQL caching on, each member gets their own answer', $seen === [$members['a']->id, $members['b']->id, null], json_encode($seen));
    check('cache: the second member never sees the first member’s subscription', array_column($cached[1]['result']['data']['headcountMembership']['subscriptions'] ?? [], 'id') === [$subs['b']->id]);
    check('cache: enableGraphqlCaching is restored after each query', array_column($cached, 'cachingAfter') === [true, true, true], json_encode(array_column($cached, 'cachingAfter')));

    check('cache: an aliased root field is recognised', Headcount::queryTouchesHeadcount('{ me: headcountMembership { isMember } }'));
    check('cache: a field in a fragment is recognised', Headcount::queryTouchesHeadcount('query { ...F } fragment F on Query { headcountAccess(entryId: 1) { allowed } }'));
    check('cache: unrelated queries are left cached', !Headcount::queryTouchesHeadcount('{ entries { title headcountish } }'));

    // -- Schema components --------------------------------------------------------------

    $components = Craft::$app->getGql()->getAllSchemaComponents();
    $flat = json_encode($components);
    check('schema components: both Headcount components are offered', str_contains($flat, 'headcount.plans:read') && str_contains($flat, 'headcount.membership:read'));
} catch (Throwable $e) {
    check('setup', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach (array_reverse($made['elements']) as $element) {
        $elements->deleteElement($element, true);
    }
    foreach ($made['rules'] as $id) {
        $plugin->gating->deleteRuleById($id);
    }
    foreach ($made['plans'] as $id) {
        $plugin->plans->deletePlanById($id);
    }
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
