const officeLaunch = document.getElementById('officeLaunch');
if (officeLaunch) {
    officeLaunch.submit();
    // The office server receives the token in a POST, never in the host-page URL.
    officeLaunch.querySelector('[name="access_token"]').value = '';
    const status = document.getElementById('officeStatus');
    const expires = Number(status.dataset.expires) * 1000;
    const timer = window.setInterval(() => {
        if (Date.now() >= expires) {
            status.textContent = 'Ofis oturumunun süresi doldu. Kaydedilmemiş değişiklikler varsa ofis içinden bir kopya alın; ardından dokümanı yeniden açın.';
            status.classList.add('error');
            window.clearInterval(timer);
        }
    }, 1000);
}
