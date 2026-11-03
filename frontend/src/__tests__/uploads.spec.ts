import { describe, expect, it } from 'vitest'
import { UPLOAD_LIMITS, planUploadBatches } from '../constants/uploads'

const MB = 1024 * 1024
const files = (...sizes: number[]) => sizes.map((size) => ({ size }))
const range = (from: number, to: number) => Array.from({ length: to - from }, (_, i) => from + i)

describe('planUploadBatches', () => {
  it('sends at most 20 files a request, in order', () => {
    const plan = planUploadBatches(files(...Array<number>(28).fill(MB)))

    expect(plan.batches).toEqual([range(0, 20), range(20, 28)])
    expect(plan.tooLarge).toEqual([])
  })

  it('sends exactly 20 in one request, and the 21st in another', () => {
    expect(planUploadBatches(files(...Array<number>(20).fill(MB))).batches).toEqual([range(0, 20)])
    expect(planUploadBatches(files(...Array<number>(21).fill(MB))).batches).toEqual([
      range(0, 20),
      [20],
    ])
  })

  it('starts a new request before the bytes would pass the request limit', () => {
    const plan = planUploadBatches(files(60 * MB, 50 * MB, 40 * MB, 10 * MB, 1 * MB))

    expect(plan.batches).toEqual([[0], [1, 2, 3], [4]])
  })

  it('sends a file at the size limit on its own', () => {
    const plan = planUploadBatches(files(MB, UPLOAD_LIMITS.bytesPerFile, MB))

    expect(plan.batches).toEqual([[0], [1], [2]])
    expect(plan.tooLarge).toEqual([])
  })

  it('sets aside a file over the size limit and batches the rest around it', () => {
    const plan = planUploadBatches(files(MB, UPLOAD_LIMITS.bytesPerFile + 1, MB))

    expect(plan.batches).toEqual([[0, 2]])
    expect(plan.tooLarge).toEqual([1])
  })

  it('plans nothing for no files', () => {
    expect(planUploadBatches([])).toEqual({ batches: [], tooLarge: [] })
  })

  it('takes other limits', () => {
    const plan = planUploadBatches(files(5, 5, 5, 20), {
      filesPerRequest: 2,
      bytesPerFile: 15,
      bytesPerRequest: 12,
    })

    expect(plan.batches).toEqual([[0, 1], [2]])
    expect(plan.tooLarge).toEqual([3])
  })
})
