export default async function mount({ host, field, options, changed, signal }) {
  await Promise.resolve();
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = 'Append suffix';
  host.append(button);
  button.addEventListener('click', () => {
    field.value += options.suffix;
    changed();
  }, { signal });
  return {
    getValue: () => field.value,
    setValue: (value) => { field.value = value ?? ''; },
    validate: () => field.value.includes('invalid') ? 'Invalid example' : '',
    destroy: () => button.remove(),
  };
}
