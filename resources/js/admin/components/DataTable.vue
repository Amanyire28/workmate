<template>
  <div class="relative">
    <!-- Desktop / tablet: a real table -->
    <div class="hidden md:block overflow-x-auto rounded-xl wm-surface">
      <table class="w-full text-[13.5px]">
        <caption class="sr-only">{{ caption }}</caption>
        <thead class="bg-[#F8FAFC] text-[12.5px] text-[#475569]">
          <tr>
            <th
              v-for="c in columns"
              :key="c.key"
              scope="col"
              class="h-10 px-3 font-semibold whitespace-nowrap border-b border-[#E2E8F0]"
              :class="[c.align === 'right' ? 'text-right' : 'text-left', c.hideBelow === 'lg' ? 'hidden lg:table-cell' : '', c.headerClass]"
              :style="c.width ? { width: c.width } : undefined"
              :aria-sort="c.sortable ? (sort.key === c.key ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none') : undefined"
            >
              <button
                v-if="c.sortable"
                type="button"
                class="inline-flex items-center gap-1 -mx-1 px-1 h-8 rounded hover:text-ink"
                @click="toggleSort(c.key)"
              >
                {{ c.label }}
                <i
                  :class="['bi text-[11px]', sort.key === c.key ? (sort.dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up text-[#CBD5E1]']"
                  aria-hidden="true"
                ></i>
              </button>
              <template v-else>{{ c.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody :class="{ 'opacity-50': loading && rows.length }" :aria-busy="loading ? 'true' : undefined">
          <tr
            v-for="row in rows"
            :key="row[rowKey]"
            class="border-b border-[#F1F5F9] last:border-0 transition-colors"
            :class="[clickable ? 'cursor-pointer hover:bg-[#F8FAFC]' : '', selectedKey === row[rowKey] ? '!bg-[#EFF6FF]' : '']"
            @click="clickable && $emit('row-click', row)"
          >
            <td
              v-for="(c, i) in columns"
              :key="c.key"
              class="px-3 py-2.5 align-top"
              :class="[c.align === 'right' ? 'text-right tabular-nums' : '', c.hideBelow === 'lg' ? 'hidden lg:table-cell' : '', c.class]"
            >
              <!-- The first cell holds the row's real control, so keyboard users can open it too -->
              <button
                v-if="i === 0 && clickable"
                type="button"
                class="text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded"
                :aria-label="rowLabel ? rowLabel(row) : undefined"
                @click.stop="$emit('row-click', row)"
              >
                <slot :name="`cell-${c.key}`" :row="row"><slot name="cell" :row="row" :column="c">{{ row[c.key] }}</slot></slot>
              </button>
              <slot v-else :name="`cell-${c.key}`" :row="row"><slot name="cell" :row="row" :column="c">{{ row[c.key] }}</slot></slot>
            </td>
          </tr>
        </tbody>
      </table>
      <slot v-if="!rows.length" name="empty" />
    </div>

    <!-- Phones: each row as a compact card -->
    <ul class="md:hidden flex flex-col gap-2" :class="{ 'opacity-50': loading && rows.length }" :aria-label="caption">
      <li v-for="row in rows" :key="row[rowKey]">
        <component
          :is="clickable ? 'button' : 'div'"
          :type="clickable ? 'button' : undefined"
          class="w-full text-left rounded-xl wm-surface p-3.5 flex flex-col gap-1.5"
          :class="{ 'active:bg-[#F8FAFC]': clickable, '!border-primary': selectedKey === row[rowKey] }"
          :aria-label="clickable && rowLabel ? rowLabel(row) : undefined"
          @click="clickable && $emit('row-click', row)"
        >
          <div class="text-[14px] text-ink"><slot :name="`cell-${columns[0].key}`" :row="row"><slot name="cell" :row="row" :column="columns[0]">{{ row[columns[0].key] }}</slot></slot></div>
          <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[12.5px]">
            <template v-for="c in columns.slice(1)" :key="c.key">
              <dt class="text-[#64748B]">{{ c.label }}</dt>
              <dd class="text-ink min-w-0"><slot :name="`cell-${c.key}`" :row="row"><slot name="cell" :row="row" :column="c">{{ row[c.key] }}</slot></slot></dd>
            </template>
          </dl>
        </component>
      </li>
    </ul>
    <div v-if="!rows.length" class="md:hidden rounded-xl wm-surface"><slot name="empty" /></div>
  </div>
</template>

<script setup>
const props = defineProps({
  columns:     { type: Array, required: true },    // [{ key, label, sortable?, align?, width?, hideBelow?, class? }]
  rows:        { type: Array, required: true },
  rowKey:      { type: String, default: 'id' },
  sort:        { type: Object, default: () => ({ key: '', dir: 'desc' }) },
  loading:     { type: Boolean, default: false },
  clickable:   { type: Boolean, default: false },
  selectedKey: { type: [String, Number], default: null },
  rowLabel:    { type: Function, default: null },  // accessible name for the row control
  caption:     { type: String, required: true },
});
const emit = defineEmits(['update:sort', 'row-click']);

function toggleSort(key) {
  const dir = props.sort.key === key && props.sort.dir === 'desc' ? 'asc' : 'desc';
  emit('update:sort', { key, dir });
}
</script>
