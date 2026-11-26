export interface ExportFile {
  filename: string;
  mime_type: string;
  content: string;
}

/** Offers a generated file as a download (the content stays in memory; nothing is uploaded). */
export function downloadFile(file: ExportFile, doc: Document = document): void {
  const url = URL.createObjectURL(
    new Blob([file.content], { type: `${file.mime_type};charset=utf-8` }),
  );
  const link = doc.createElement('a');
  link.href = url;
  link.download = file.filename;
  link.rel = 'noopener';
  doc.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
