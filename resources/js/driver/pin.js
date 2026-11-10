// Trip PIN keypad state (driver app): four digits, then the start request. Errors from the server: a wrong PIN
// clears the entry and says so; a lock (too many wrong entries) disables the pad until it lifts.
// Pure; tested in tests/js/driver-pin.test.mjs.

export class PinPad {
    constructor(length = 4) {
        this.length = length;
        this.digits = '';
        this.lockedUntil = 0;
        this.error = null;
    }

    locked(nowMs = Date.now()) {
        return nowMs < this.lockedUntil;
    }

    press(d, nowMs = Date.now()) {
        if (this.locked(nowMs) || !/^[0-9]$/.test(String(d)) || this.digits.length >= this.length) return false;
        this.digits += String(d);
        this.error = null;

        return true;
    }

    back() {
        this.digits = this.digits.slice(0, -1);
    }

    clear() {
        this.digits = '';
    }

    complete() {
        return this.digits.length === this.length;
    }

    /** Apply the server's refusal: {error: 'pin_wrong'|'pin_locked', message}. */
    refused(error, nowMs = Date.now(), lockMinutes = 10) {
        this.digits = '';
        this.error = error;
        if (error === 'pin_locked') this.lockedUntil = nowMs + lockMinutes * 60000;
    }
}
