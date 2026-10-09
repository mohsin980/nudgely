// Settings forms: warn before leaving with unsaved changes (closing the tab or navigating away).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('unsavedChanges', () => ({
        dirty: false,
        init() {
            const markDirty = () => { this.dirty = true; };
            this.$el.addEventListener('input', markDirty);
            this.$el.addEventListener('change', markDirty);
            window.addEventListener('settings-saved', () => { this.dirty = false; });
            window.addEventListener('beforeunload', (event) => {
                if (this.dirty && this.$el.isConnected) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            document.addEventListener('livewire:navigate', (event) => {
                if (this.dirty && this.$el.isConnected && ! window.confirm('You have unsaved changes. Leave this page?')) {
                    event.preventDefault();
                }
            });
        },
    }));
});
