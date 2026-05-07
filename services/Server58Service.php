<?php
/**
 * Server58Service.php - 58번 멀티 대시보드 서버 연동 서비스
 * 회원가입 동기화, 봇 상태 Push, DB 백업 수신, 실시간 상태 조회
 */

class Server58Service {

    private string $base_url;
    private string $api_key;
    private int    $timeout;

    // ============================================================
    // 생성자
    // ============================================================
    public function __construct() {
        $this->base_url = rtrim(SERVER_58_URL, '/');
        $this->api_key  = SERVER_58_KEY;
        $this->timeout  = 15; // 기본 타임아웃 15초
    }

    // ============================================================
    // 핵심: 58번 서버 API 호출 공통 함수
    // ============================================================
    private function request(string $endpoint, array $payload = [], string $method = 'POST'): array {
        $url = $this->base_url . $endpoint;

        // HMAC-SHA256 서명 생성 (중간자 공격 방지)
        $timestamp   = time();
        $body_json   = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $sign_string = $timestamp . '|' . $endpoint . '|' . $body_json;
        $signature   = hash_hmac('sha256', $sign_string, $this->api_key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body_json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->api_key,        // API 키
                'X-Timestamp: ' . $timestamp,            // 타임스탬프 (리플레이 방지)
                'X-Signature: ' . $signature,            // HMAC 서명
                'X-Source: 147',                         // 발신 서버 식별
            ],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        $result = [
            'success'   => ($http_code >= 200 && $http_code < 300),
            'http_code' => $http_code,
            'body'      => $body,
            'data'      => json_decode($body, true),
            'error'     => $curl_err,
        ];

        // 동기화 로그 자동 기록
        $this->logSync($endpoint, $payload, $result);

        return $result;
    }

    // ============================================================
    // 동기화 로그 기록
    // ============================================================
    private function logSync(string $action, array $req, array $res): void {
        try {
            db_execute(
                "INSERT INTO server58_sync_log (action, target_uid, request, response, http_code, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $action,
                    $req['uid'] ?? $req['mb_uid'] ?? null,
                    json_encode($req, JSON_UNESCAPED_UNICODE),
                    $res['body'],
                    $res['http_code'],
                    $res['success'] ? 'success' : 'failed',
                ]
            );
        } catch (Exception $e) {
            error_log('[Server58 로그 실패] ' . $e->getMessage());
        }
    }

    // ============================================================
    // 1. 회원 정보 Push (회원 생성/수정 시 58번 동기화)
    // ============================================================
    public function pushMember(array $member_data): array {
        // 민감 정보 제거 후 전송
        $safe_data = [
            'uid'          => $member_data['rx_uid']       ?? '',
            'mb_id'        => $member_data['mb_id']        ?? '',
            'mb_name'      => $member_data['mb_name']      ?? '',
            'mb_hp'        => $member_data['mb_hp']        ?? '',
            'mb_email'     => $member_data['mb_email']     ?? '',
            'mb_center'    => $member_data['mb_center']    ?? '',
            'rx_ce'        => $member_data['rx_ce']        ?? 0,
            'mb_class'     => $member_data['mb_class']     ?? 1,
            'rx_acount'    => $member_data['rx_acount']    ?? 0,
            'rx_rate'      => $member_data['rx_rate']      ?? 0,
            'rx_send_ok'   => $member_data['rx_send_ok']  ?? 'N',
            'is_active'    => empty($member_data['mb_intercept_date']),
            'synced_at'    => date('Y-m-d H:i:s'),
        ];

        return $this->request('/api/v1/member/sync', $safe_data);
    }

    // ============================================================
    // 2. 봇 상태 Push (시그널 ON/OFF, 포지션 변경 시)
    // ============================================================
    public function pushBotStatus(string $uid, string $status, array $extra = []): array {
        $payload = array_merge([
            'uid'    => $uid,
            'status' => $status, // 'on', 'off', 'entry', 'close', 'error'
            'ts'     => time(),
        ], $extra);

        return $this->request('/api/v1/bot/status', $payload);
    }

    // ============================================================
    // 3. 전체 봇 상태 일괄 Push (대시보드 동기화)
    // ============================================================
    public function pushAllBotStatus(): array {
        $members = db_rows(
            "SELECT rx_uid, rx_send_ok, rx_acount, rx_ce, mb_center
             FROM g5_member WHERE rx_send_ok IN ('Y','S','R')"
        );

        $batch = array_map(fn($m) => [
            'uid'    => $m['rx_uid'],
            'status' => $m['rx_send_ok'],
            'amount' => $m['rx_acount'],
        ], $members);

        return $this->request('/api/v1/bot/batch_status', ['bots' => $batch]);
    }

    // ============================================================
    // 4. 58번 서버에서 회원 목록 가져오기 (가입 신청 동기화)
    // ============================================================
    public function pullPendingMembers(): array {
        $result = $this->request('/api/v1/member/pending', [], 'GET');
        if ($result['success'] && isset($result['data']['members'])) {
            return $result['data']['members'];
        }
        return [];
    }

    // ============================================================
    // 5. 58번 서버 헬스체크 (연결 상태 확인)
    // ============================================================
    public function healthCheck(): array {
        $old_timeout = $this->timeout;
        $this->timeout = 5; // 헬스체크는 5초 타임아웃
        $result = $this->request('/api/v1/health', [], 'GET');
        $this->timeout = $old_timeout;
        return [
            'connected'  => $result['success'],
            'http_code'  => $result['http_code'],
            'latency_ms' => null, // 실제 latency 측정 필요 시 microtime 사용
            'error'      => $result['error'],
        ];
    }

    // ============================================================
    // 6. 회원 비밀번호 해시 동기화 (147 → 58)
    // ============================================================
    public function syncPassword(string $uid, string $pw_hash): array {
        return $this->request('/api/v1/member/password_sync', [
            'uid'     => $uid,
            'pw_hash' => $pw_hash,
        ]);
    }

    // ============================================================
    // 7. 도메인 설정 Push (멀티도메인 변경 시)
    // ============================================================
    public function pushDomainConfig(array $domain_data): array {
        // 58번 서버 배포 경로: /api/receive_domain.php
        return $this->request('/api/receive_domain.php', $domain_data);
    }
}
