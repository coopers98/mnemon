{{--
    Wing selection behaviour, shared by both consent screens.

    syncWingList: per-wing checkboxes follow the "all wings" master toggle.
    syncApproveEnabled: an empty selection with "all wings" unchecked is
    deny-all — a real choice, but not one anyone makes by accident, so the
    approve button stays disabled until the selection says something.
--}}
const allWingsCheckbox = document.getElementById('all-wings');
const wingList = document.getElementById('wing-list');
const wingCheckboxes = document.querySelectorAll('.wing-checkbox');

function syncWingList() {
    if (!wingList) return;
    if (allWingsCheckbox.checked) {
        wingList.classList.add('opacity-40', 'pointer-events-none');
        wingCheckboxes.forEach(function (cb) {
            cb.disabled = true;
            cb.checked = false;
        });
    } else {
        wingList.classList.remove('opacity-40', 'pointer-events-none');
        wingCheckboxes.forEach(function (cb) {
            cb.disabled = false;
        });
    }
}

function syncApproveEnabled() {
    var any = Array.prototype.some.call(wingCheckboxes, function (cb) { return cb.checked; });
    button.disabled = !allWingsCheckbox.checked && !any;
}

function syncWings() {
    syncWingList();
    syncApproveEnabled();
}

allWingsCheckbox.addEventListener('change', syncWings);
wingCheckboxes.forEach(function (cb) { cb.addEventListener('change', syncApproveEnabled); });
syncWings(); // run on page load
