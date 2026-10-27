export function photoInputError(file) {
    if (!file) return 'Choose a JPEG or PNG photo.';
    if (file.size > 5 * 1024 * 1024) return 'Each photo must be at most 5 MiB.';
    if (!(/\.jpe?g$/i.test(file.name) && file.type === 'image/jpeg')
        && !(/\.png$/i.test(file.name) && file.type === 'image/png')) {
        return 'Choose a JPEG or PNG with a matching file extension.';
    }
    return '';
}
