<?php
# manage ip range in phpipam using API
# by Benjamin Maze December 2024

include('config.php');

$curl = curl_init();

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getRequestString(array $source, string $key, string $default = ''): string
{
    if (!isset($source[$key])) {
        return $default;
    }

    return trim((string)$source[$key]);
}

function getRequestInt(array $source, string $key, ?int $default = null): ?int
{
    if (!isset($source[$key]) || $source[$key] === '') {
        return $default;
    }

    $value = filter_var($source[$key], FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function getSubnetPrefix(string $subnet): string
{
    $parts = explode('.', $subnet);
    return implode('.', array_slice($parts, 0, 3));
}

function ipamRequest(string $url, string $method, string $body = ''): array
{
    global $token;
    global $curl;

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['token: ' . $token, 'Content-Type: application/json'],
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        return [
            'ok' => false,
            'status' => 0,
            'raw' => '',
            'error' => curl_error($curl),
        ];
    }

    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    return [
        'ok' => $status === 200,
        'status' => $status,
        'raw' => $response,
        'error' => null,
    ];
}

function decodeApiResponse(array $result): array
{
    if (!$result['ok']) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'data' => null,
            'message' => $result['error'] ?: ('API error HTTP ' . $result['status']),
        ];
    }

    $decoded = json_decode($result['raw'], true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $result['status'],
            'data' => null,
            'message' => 'Invalid JSON response from API',
        ];
    }

    return [
        'ok' => true,
        'status' => $result['status'],
        'data' => $decoded,
        'message' => null,
    ];
}

function renderSubnetSelector(array $subnets, ?int $selectedSubnet): void
{
    echo '<form action="index.php" method="get">';
    echo '<label for="subnet">choose your subnet in the list</label>';
    echo '<select name="subnet" id="subnet" onchange="this.form.submit()">';
    echo '<option value=""></option>';

    foreach ($subnets as $value) {
        $id = (int)($value['id'] ?? 0);
        $description = (string)($value['description'] ?? '');
        $selected = ($selectedSubnet !== null && $selectedSubnet === $id) ? ' selected' : '';
        echo '<option value="' . h($id) . '"' . $selected . '>' . h($description) . '</option>';
    }

    echo '</select>';
    echo '</form>';
}

function renderRangeForm(int $subnetId, string $subnetPrefix): void
{
    $start = getRequestString($_POST, 'start');
    $stop = getRequestString($_POST, 'stop');
    $tags = getRequestString($_POST, 'tags');
    $hostname = getRequestString($_POST, 'hostname');
    $description = getRequestString($_POST, 'description');
    $deleteChecked = isset($_POST['delete']) && $_POST['delete'] === 'on' ? ' checked' : '';

    echo '<form action="index.php?subnet=' . h($subnetId) . '" method="POST">';
    echo '<table>';
    echo '<tr>';
    echo '<td><b>' . h($subnetPrefix) . '.</b></td>';
    echo '<td>from<br><input type="text" name="start" value="' . h($start) . '"></td>';
    echo '<td>to<br><input type="text" name="stop" value="' . h($stop) . '"></td>';
    echo '<input type="hidden" name="subnet" value="' . h($subnetId) . '">';
    echo '<input type="hidden" name="action" value="editRange"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td>tag:<br><select name="tags" id="tags">';
    echo '<option value=""></option>';
    $tagOptions = [
        '1' => 'Offline',
        '2' => 'Used',
        '3' => 'Reserved',
        '4' => 'DHCP',
    ];
    foreach ($tagOptions as $value => $label) {
        $selected = $tags === $value ? ' selected' : '';
        echo '<option value="' . h($value) . '"' . $selected . '>' . h($label) . '</option>';
    }
    echo '</select></td>';
    echo '<td>hostname<br><input type="text" name="hostname" value="' . h($hostname) . '"></td>';
    echo '<td>description<br><input type="text" name="description" value="' . h($description) . '"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td>delete <input type="checkbox" name="delete"' . $deleteChecked . '></td>';
    echo '<td colspan="2"><input type="submit"></td>';
    echo '</tr>';
    echo '</table>';
    echo '</form>';
}

function renderDeleteConfirmation(int $subnetId, int $start, int $stop): void
{
    echo '<form name="confirmDelete" action="index.php?subnet=' . h($subnetId) . '" method="POST">';
    echo '<input type="hidden" name="confDel" value="yes">';
    echo '<input type="hidden" name="action" value="editRange">';
    echo '<input type="hidden" name="start" value="' . h($start) . '">';
    echo '<input type="hidden" name="stop" value="' . h($stop) . '">';
    echo '<input type="hidden" name="subnet" value="' . h($subnetId) . '">';
    echo '<input type="submit" value="confirm delete ?">';
    echo '</form>';
}

function buildPatchBody(string $tag, string $hostname, string $description): string
{
    $payload = [];

    if ($tag !== '') {
        $payload['tag'] = $tag;
    }
    if ($hostname !== '') {
        $payload['hostname'] = $hostname;
    }
    if ($description !== '') {
        $payload['description'] = $description;
    }

    return json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function buildCreateBody(string $ip, int $subnetId, string $tag, string $hostname, string $description): string
{
    $payload = [
        'ip' => $ip,
        'subnetId' => $subnetId,
        'hostname' => $hostname,
        'description' => $description,
    ];

    if ($tag !== '') {
        $payload['tag'] = $tag;
    }

    return json_encode($payload, JSON_UNESCAPED_SLASHES);
}

echo '<b>update ip range phpipam</b><br><br>';

$selectedSubnet = getRequestInt($_GET, 'subnet');

$subnetListResult = decodeApiResponse(ipamRequest($APIurl . '/subnets', 'GET'));
if (!$subnetListResult['ok']) {
    echo '<p>Unable to fetch subnets: ' . h($subnetListResult['message']) . '</p>';
    exit;
}

$subnetListData = $subnetListResult['data']['data'] ?? [];
renderSubnetSelector(is_array($subnetListData) ? $subnetListData : [], $selectedSubnet);

if ($selectedSubnet !== null) {
    $subnetResult = decodeApiResponse(ipamRequest($APIurl . '/subnets/' . $selectedSubnet, 'GET'));
    if (!$subnetResult['ok'] || !isset($subnetResult['data']['data']['subnet'])) {
        echo '<p>Unable to fetch selected subnet details.</p>';
        exit;
    }

    $subnet = (string)$subnetResult['data']['data']['subnet'];
    $subnetPrefix = getSubnetPrefix($subnet);

    renderRangeForm($selectedSubnet, $subnetPrefix);

    if (getRequestString($_POST, 'action') === 'editRange') {
        $start = getRequestInt($_POST, 'start');
        $stop = getRequestInt($_POST, 'stop');

        if ($start === null || $stop === null || $start < 0 || $stop > 255 || $start > $stop) {
            echo '<br>Invalid range. Please provide start/stop between 0 and 255, and start <= stop.';
            curl_close($curl);
            exit;
        }

        $tag = getRequestString($_POST, 'tags');
        $hostname = getRequestString($_POST, 'hostname');
        $description = getRequestString($_POST, 'description');
        $confirmDelete = getRequestString($_POST, 'confDel') === 'yes';

        for ($i = $start; $i <= $stop; $i++) {
            $ip = $subnetPrefix . '.' . $i;
            $searchResult = decodeApiResponse(ipamRequest($APIurl . '/addresses/search/' . $ip, 'GET'));

            $exists = $searchResult['ok']
                && isset($searchResult['data']['code'])
                && (string)$searchResult['data']['code'] === '200'
                && !empty($searchResult['data']['data'][0]['id']);

            if ($exists && !$confirmDelete) {
                $addressDetail = $searchResult['data']['data'][0];
                $id = (int)$addressDetail['id'];
                $patchBody = buildPatchBody($tag, $hostname, $description);

                if ($patchBody === '{}' || $patchBody === '[]') {
                    echo '<br>' . h($ip) . ' with id ' . h($id) . ' - no field to update';
                    continue;
                }

                $response = decodeApiResponse(ipamRequest($APIurl . '/addresses/' . $id, 'PATCH', $patchBody));
                echo '<br>' . h($ip) . ' with id ' . h($id) . ' - ' . h($response['ok'] ? 'updated' : $response['message']);
                continue;
            }

            if (!$exists && !$confirmDelete) {
                $createBody = buildCreateBody($ip, $selectedSubnet, $tag, $hostname, $description);
                $response = decodeApiResponse(ipamRequest($APIurl . '/addresses/', 'POST', $createBody));
                echo '<br>' . h($ip) . ' not found - ' . h($response['ok'] ? 'created' : $response['message']);
                continue;
            }

            if ($exists && $confirmDelete) {
                $addressDetail = $searchResult['data']['data'][0];
                $id = (int)$addressDetail['id'];
                $response = decodeApiResponse(ipamRequest($APIurl . '/addresses/' . $id, 'DELETE'));
                echo '<br>delete ip address ' . h($ip) . ' - ' . h($response['ok'] ? 'deleted' : $response['message']);
                continue;
            }

            echo '<br>' . h($ip) . ' - nothing to delete';
        }

        if (isset($_POST['delete']) && $_POST['delete'] === 'on') {
            renderDeleteConfirmation($selectedSubnet, $start, $stop);
        }
    }
}

curl_close($curl);
