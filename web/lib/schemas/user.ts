import { z } from "zod";
import { single } from "./common";

// GET /api/user（docs/01 5.1）
export const userSchema = z.object({
  id: z.number(),
  name: z.string(),
  email: z.string(),
  company: z.object({
    id: z.number(),
    name: z.string(),
  }),
});
export const userResponse = single(userSchema);
export type User = z.infer<typeof userSchema>;

// POST /login の 200（Fortify）
export const loginResponse = z.object({
  two_factor: z.boolean(),
});
