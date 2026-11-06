/**
 * How the upload page fits files into requests the server takes whole.
 *
 * PHP applies these limits before the app sees a request, and quietly: past
 * max_file_uploads it drops the extra files, and past post_max_size it drops
 * the whole body. So a large selection goes in several requests, each within
 * every limit. They mirror the server's settings; keep them in sync with
 * backend/public/.user.ini (and its mod_php copy in .htaccess) and the
 * droplet's php.ini.
 */
interface UploadLimits {
  /** max_file_uploads: PHP's default of 20, which the droplet's php.ini keeps. */
  filesPerRequest: number
  /** upload_max_filesize 100M (backend/public/.user.ini). */
  bytesPerFile: number
  /** post_max_size 105M, less room for the multipart encoding around each file. */
  bytesPerRequest: number
}

export const UPLOAD_LIMITS: UploadLimits = {
  filesPerRequest: 20,
  bytesPerFile: 100 * 1024 * 1024,
  bytesPerRequest: 100 * 1024 * 1024,
}

interface UploadPlan {
  /** Positions in the file list, request by request, in their original order. */
  batches: number[][]
  /** Positions of the files too large to send at all. */
  tooLarge: number[]
}

/** Splits files, in order, into requests within the limits. */
export function planUploadBatches(
  files: readonly { size: number }[],
  limits: UploadLimits = UPLOAD_LIMITS,
): UploadPlan {
  const plan: UploadPlan = { batches: [], tooLarge: [] }
  let batch: number[] = []
  let bytes = 0

  files.forEach((file, index) => {
    if (file.size > limits.bytesPerFile) {
      plan.tooLarge.push(index)
      return
    }
    const full =
      batch.length === limits.filesPerRequest || bytes + file.size > limits.bytesPerRequest
    if (full && batch.length > 0) {
      plan.batches.push(batch)
      batch = []
      bytes = 0
    }
    batch.push(index)
    bytes += file.size
  })
  if (batch.length > 0) {
    plan.batches.push(batch)
  }

  return plan
}
