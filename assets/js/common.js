/**
 * common.js - 공통 JavaScript
 * 사이드바 Collapse, 테마 토글, 시계, AJAX 헬퍼
 */

/* ── 사이드바 Collapse ────────────────────────────── */
const sidebar   = document.getElementById('sidebar');
const topbar    = document.getElementById('topbar');
const mainCont  = document.getElementById('main-content');
const toggleBtn = document.getElementById('sidebar-toggle');
const overlay   = document.getElementById('sidebar-overlay');

// 로컬스토리지에서 Collapse 상태 불러오기
let sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

function applySidebarState() {
  if (window.innerWidth <= 768) {
    // 모바일: 슬라이드 방식
    sidebar.classList.remove('collapsed');
    return;
  }
  if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
    topbar.classList.add('sidebar-collapsed');
    mainCont.classList.add('sidebar-collapsed');
  } else {
    sidebar.classList.remove('collapsed');
    topbar.classList.remove('sidebar-collapsed');
    mainCont.classList.remove('sidebar-collapsed');
  }
}

// 초기 상태 적용
applySidebarState();

// 햄버거 버튼 클릭
if (toggleBtn) {
  toggleBtn.addEventListener('click', () => {
    if (window.innerWidth <= 768) {
      // 모바일: 오버레이 방식
      sidebar.classList.toggle('mobile-open');
      overlay.style.display = sidebar.classList.contains('mobile-open') ? 'block' : 'none';
    } else {
      // PC: Collapse 방식
      sidebarCollapsed = !sidebarCollapsed;
      localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
      applySidebarState();
    }
  });
}

function closeMobileSidebar() {
  sidebar.classList.remove('mobile-open');
  overlay.style.display = 'none';
}

// 화면 크기 변경 시 재적용
window.addEventListener('resize', applySidebarState);

/* ── 다크/화이트 테마 토글 ────────────────────────── */
const themeToggle = document.getElementById('theme-toggle');
const themeIcon   = document.getElementById('theme-icon');
let currentTheme  = localStorage.getItem('theme') || 'dark';

function applyTheme(theme) {
  if (theme === 'light') {
    document.body.classList.add('light-mode');
    if (themeIcon) themeIcon.textContent = '🌙';
    if (themeToggle) themeToggle.querySelector('span + *') || null;
  } else {
    document.body.classList.remove('light-mode');
    if (themeIcon) themeIcon.textContent = '☀️';
  }
}

applyTheme(currentTheme);

if (themeToggle) {
  themeToggle.addEventListener('click', () => {
    currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', currentTheme);
    applyTheme(currentTheme);
  });
}

/* ── 실시간 시계 ──────────────────────────────────── */
const clockEl = document.getElementById('live-clock');
function updateClock() {
  if (!clockEl) return;
  const now = new Date();
  // 한국 시간 형식으로 표시
  clockEl.textContent = now.toLocaleString('ko-KR', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    hour12: false
  });
}
updateClock();
setInterval(updateClock, 1000);

/* ── CSRF 토큰 (AJAX 공통) ────────────────────────── */
// 페이지의 CSRF 토큰 가져오기 (헤더에 메타 태그로 삽입된 값)
function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

/* ── AJAX 헬퍼 함수 ───────────────────────────────── */
/**
 * adminAjax - 관리자 AJAX 요청 표준 함수
 * @param {string} url - 요청 URL
 * @param {object} data - 전송 데이터 (자동으로 csrf_token 추가)
 * @param {function} onSuccess - 성공 콜백 (응답 데이터 전달)
 * @param {function} onError - 실패 콜백
 */
function adminAjax(url, data, onSuccess, onError) {
  // CSRF 토큰 자동 추가
  data._csrf_token = getCsrfToken();

  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify(data)
  })
  .then(res => res.json())
  .then(json => {
    if (json.success) {
      onSuccess && onSuccess(json);
    } else {
      const msg = json.message || '오류가 발생했습니다.';
      showAlert(msg, 'danger');
      onError && onError(json);
    }
  })
  .catch(err => {
    console.error('[AJAX 오류]', err);
    showAlert('서버 통신 오류가 발생했습니다.', 'danger');
    onError && onError(err);
  });
}

/* ── 알림 메시지 표시 ─────────────────────────────── */
/**
 * showAlert - 화면 상단에 알림 메시지 표시
 * @param {string} msg - 메시지 내용
 * @param {string} type - 'success' | 'danger' | 'warning' | 'info'
 * @param {number} duration - 자동 닫힘 시간(ms), 0이면 수동 닫기
 */
function showAlert(msg, type = 'info', duration = 3500) {
  // 기존 알림 제거
  const existingAlert = document.getElementById('global-alert');
  if (existingAlert) existingAlert.remove();

  const icons = { success: '✅', danger: '❌', warning: '⚠️', info: '💡' };
  const div = document.createElement('div');
  div.id = 'global-alert';
  div.className = `alert-dark ${type}`;
  div.style.cssText = `
    position: fixed; top: 72px; right: 24px; z-index: 9999;
    min-width: 280px; max-width: 420px;
    padding: 14px 18px; border-radius: 12px;
    animation: slideInRight 0.3s ease;
    box-shadow: 0 8px 30px rgba(0,0,0,0.4);
    display: flex; align-items: center; gap: 10px;
  `;
  div.innerHTML = `<span>${icons[type] || '💡'}</span><span style="flex:1">${msg}</span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;cursor:pointer;font-size:1.1rem;">×</button>`;

  document.body.appendChild(div);

  // 자동 닫힘
  if (duration > 0) setTimeout(() => div.remove(), duration);
}

/* ── 숫자 포맷 ────────────────────────────────────── */
function numFmt(n, decimals = 0) {
  if (n == null || n === '') return '-';
  return parseFloat(n).toLocaleString('ko-KR', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals
  });
}

/* ── 확인 다이얼로그 (Promise 기반) ──────────────────  */
function confirmAction(msg) {
  return new Promise(resolve => {
    // 기본 confirm 사용 (커스텀 모달로 교체 가능)
    resolve(window.confirm(msg));
  });
}

/* ── CSS 애니메이션 추가 ──────────────────────────── */
const style = document.createElement('style');
style.textContent = `
  @keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
  }
`;
document.head.appendChild(style);

/* ── DataTables 기본 한국어 설정 ─────────────────── */
// DataTables 한국어 언어팩
const dtKoLang = {
  sEmptyTable: '테이블에 데이터가 없습니다',
  sInfo: '_START_ - _END_ / _TOTAL_ 건',
  sInfoEmpty: '0 건',
  sInfoFiltered: '(전체 _MAX_ 건 중 검색 결과)',
  sLengthMenu: '_MENU_ 개씩 보기',
  sLoadingRecords: '로딩 중...',
  sProcessing: '<div class="spinner"></div>',
  sSearch: '검색:',
  sZeroRecords: '검색 결과가 없습니다',
  oPaginate: { sFirst: '처음', sLast: '마지막', sNext: '다음', sPrevious: '이전' }
};

// DataTables 자동 초기화 (data-dt 속성이 있는 테이블)
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('table[data-dt]').forEach(table => {
    // [버그수정] colspan 포함 행 제거 (DataTables 'Incorrect column count' 에러 방지)
    // 빈 데이터 표시용 colspan 행이 있으면 DataTables가 컬럼 수 불일치 오류 발생
    table.querySelectorAll('tbody tr').forEach(tr => {
      const tds = tr.querySelectorAll('td');
      if (tds.length === 1 && tds[0].hasAttribute('colspan')) {
        tr.remove(); // colspan 행 제거 → DataTables의 emptyTable 메시지로 대체
      }
    });

    $(table).DataTable({
      language: dtKoLang,
      responsive: true,
      pageLength: 25,
      order: [],
      dom: "<'row'<'col-sm-6'l><'col-sm-6'f>><t><'row'<'col-sm-6'i><'col-sm-6'p>>",
    });
  });
});
