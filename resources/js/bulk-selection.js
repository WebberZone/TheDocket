/**
 * Ticking items in a list and applying a bulk action. The item IDs on the
 * page and the delete confirmation come in as data attributes on the
 * component's root element:
 *
 *   <div x-data="bulkSelection" data-ids='["1","2"]' data-confirm-delete="…">
 *
 * IDs are strings because checkbox values are.
 */
export default () => ({
    selected: [],
    ids: [],
    action: '',
    confirmDelete: '',

    init() {
        try {
            this.ids = JSON.parse(this.$el.dataset.ids || '[]').map(String);
        } catch {
            this.ids = [];
        }

        this.confirmDelete = this.$el.dataset.confirmDelete || 'Delete the selected items?';
    },

    get all() {
        return this.ids.length > 0 && this.selected.length === this.ids.length;
    },

    get count() {
        return this.selected.length;
    },

    get countLabel() {
        return `${this.selected.length} selected`;
    },

    get tagging() {
        return this.action === 'tag';
    },

    toggleAll() {
        this.selected = this.all ? [] : [...this.ids];
    },

    submit(event) {
        if (this.action === 'delete' && !window.confirm(this.confirmDelete)) {
            event.preventDefault();
        }
    },
});
